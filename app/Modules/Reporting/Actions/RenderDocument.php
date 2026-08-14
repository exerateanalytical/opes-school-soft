<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Domain\DocumentFileName;
use App\Modules\Reporting\Domain\DocumentLanguage;
use App\Modules\Reporting\Domain\DocumentReproducibilityViolation;
use App\Modules\Reporting\Domain\DompdfRenderer;
use App\Modules\Reporting\Domain\PdfRenderer;
use App\Modules\Reporting\Domain\PdfStamp;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Modules\Reporting\Domain\SeriesFormatter;
use App\Modules\Reporting\Domain\SnapshotSourceMap;
use App\Modules\Reporting\Models\DocumentPrintLog;
use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\Reporting\Models\IssuedDocument;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Fiscal\FiscalIdentityGate;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * docs/specs/10-documents.md 4.8 - THE only path to a PDF, verbatim:
 * authorize -> resolve template + version -> resolve language -> load the
 * snapshot or take the live payload -> allocate the series number (inside
 * the transaction) -> render -> hash -> write IssuedDocument (if
 * applicable) and DocumentPrintLog -> return bytes.
 *
 * Failure discipline (4.5): the series allocation, the IssuedDocument
 * insert and the print-log row are ONE transaction, committed only after
 * the PDF bytes exist and hash successfully - so a failed render consumes
 * no number and leaves no log, because the sequence UPDATE rolls back with
 * everything else.
 *
 * Reprints: `is_duplicate` is derived from the print-log count inside the
 * transaction, never passed in. The clean artefact is re-rendered from the
 * pinned template version and the immutable snapshot, re-hashed and
 * COMPARED; a mismatch is DocumentReproducibilityViolation, never a silent
 * print. Only then is the DUPLICATA (or ANNULÉ/VOID) overlay applied to the
 * output copy.
 */
final class RenderDocument
{
    private readonly PdfRenderer $pdf;

    public function __construct(?PdfRenderer $pdf = null)
    {
        // Defaulted rather than container-bound so no service provider edit
        // is needed; tests may hand in a fake, production gets dompdf.
        $this->pdf = $pdf ?? new DompdfRenderer;
    }

    /**
     * @param  string  $subjectLabel  denormalised onto the print log; must survive renames
     * @param  array<string, mixed>  $data  live documents: the view payload the owning module's
     *                                      Action assembled. Snapshot documents whose source is in
     *                                      SnapshotSourceMap IGNORE this (the snapshot is the payload);
     *                                      snapshot documents without a registered JSON source use it
     *                                      as the payload assembled from their own immutable rows.
     * @param  string|null  $seriesScopeValue  overrides the counter-scope discriminator
     *                                         (e.g. '2026' for an academic-year series)
     * @param  int|null  $supersedesIssuedDocumentId  an amendment reissue: the prior document to
     *                                                mark superseded, inside the same transaction
     */
    public function handle(
        string $templateCode,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        ?int $snapshotId = null,
        ?string $language = null,
        ?int $schoolSectionId = null,
        array $data = [],
        ?string $idempotencyKey = null,
        ?int $bulkPrintJobId = null,
        ?string $seriesScopeValue = null,
        ?int $supersedesIssuedDocumentId = null,
    ): RenderedDocument {
        // 1. Authorize. Reprint-specific permissions are checked at the
        // point the reprint is detected, under the row lock.
        Gate::authorize(Permission::DocumentsPrint->value);

        // 2. Resolve template + version.
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->where('code', $templateCode)->firstOrFail();

        if (! $template->is_active) {
            throw new DomainException(
                "Document template [{$templateCode}] is not active; an inactive template renders nothing."
            );
        }

        // 3. Resolve language: explicit request -> SchoolSection ->
        // SchoolProfile default -> en (10-documents 4.6).
        $lang = $this->resolveLanguage($language, $schoolSectionId);

        if ($template->is_snapshot_backed && $snapshotId === null) {
            throw ValidationException::withMessages([
                'snapshot_id' => "Template [{$templateCode}] is snapshot-backed; a render without a snapshot "
                    .'would be a live query wearing a certificate (10-documents 4.2).',
            ]);
        }

        $render = fn (): RenderedDocument => DB::transaction(
            fn (): RenderedDocument => $template->is_snapshot_backed
                ? $this->renderSnapshotBacked(
                    $template, $subjectType, $subjectId, $subjectLabel, (int) $snapshotId,
                    $lang, $schoolSectionId, $data, $bulkPrintJobId, $seriesScopeValue,
                    $supersedesIssuedDocumentId,
                )
                : $this->renderLive(
                    $template, $subjectType, $subjectId, $subjectLabel,
                    $lang, $schoolSectionId, $data, $bulkPrintJobId,
                ),
        );

        if ($idempotencyKey === null) {
            return $render();
        }

        // 00-core 6.2.7: a double-clicked Print submits the same key twice;
        // the second submission is refused instead of producing a DUPLICATA
        // seconds after the original.
        $lock = Cache::lock('opes.documents.render.'.$idempotencyKey, 15);

        if (! $lock->get()) {
            throw new DomainException(
                'This print request was already submitted (duplicate idempotency key); nothing was rendered twice.'
            );
        }

        try {
            return $render();
        } finally {
            $lock->release();
        }
    }

    /**
     * 00-core 6.2.5 batch signature - the bulk printer calls THIS, never the
     * single form in a loop it wrote itself. Each subject is its own
     * transaction (10-documents 18.2): one failure marks that subject failed
     * and never rolls back or blocks the others.
     *
     * @param  list<array{subject_id: int, subject_label: string, snapshot_id?: int|null, data?: array<string, mixed>}>  $subjects
     * @return array{results: array<int, RenderedDocument>, failures: array<int, string>}
     */
    public function forSubjects(
        string $templateCode,
        string $subjectType,
        array $subjects,
        ?string $language = null,
        ?int $schoolSectionId = null,
        ?int $bulkPrintJobId = null,
        ?string $seriesScopeValue = null,
    ): array {
        $results = [];
        $failures = [];

        foreach ($subjects as $subject) {
            try {
                $results[$subject['subject_id']] = $this->handle(
                    templateCode: $templateCode,
                    subjectType: $subjectType,
                    subjectId: $subject['subject_id'],
                    subjectLabel: $subject['subject_label'],
                    snapshotId: $subject['snapshot_id'] ?? null,
                    language: $language,
                    schoolSectionId: $schoolSectionId,
                    data: $subject['data'] ?? [],
                    bulkPrintJobId: $bulkPrintJobId,
                    seriesScopeValue: $seriesScopeValue,
                );
            } catch (Throwable $e) {
                $failures[$subject['subject_id']] = $e->getMessage();
            }
        }

        return ['results' => $results, 'failures' => $failures];
    }

    // -----------------------------------------------------------------------
    // Snapshot-backed path
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderSnapshotBacked(
        DocumentTemplate $template,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        int $snapshotId,
        DocumentLanguage $lang,
        ?int $schoolSectionId,
        array $data,
        ?int $bulkPrintJobId,
        ?string $seriesScopeValue,
        ?int $supersedesIssuedDocumentId,
    ): RenderedDocument {
        /** @var IssuedDocument|null $issued */
        $issued = IssuedDocument::query()
            ->where('document_template_id', $template->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('snapshot_id', $snapshotId)
            ->lockForUpdate()
            ->first();

        $snapshot = $this->loadSnapshot($template, $snapshotId, $data, $issued);

        // The envelope is the OTHER two render inputs - the letterhead and
        // the subject label. `payload_snapshot` freezes the payload, but for
        // a template with a REGISTERED SnapshotSourceMap entry the payload is
        // immutable by construction and deliberately not copied, which left
        // these two being re-derived LIVE on every reprint and rendered into
        // the hashed bytes. Renaming an assessment period therefore refused
        // every bulletin issued under the old name, permanently.
        $envelope = is_array($issued?->render_envelope) ? $issued->render_envelope : null;

        $chrome = $this->schoolChrome($template, $schoolSectionId, $snapshot['payload'], $envelope);

        if ($envelope !== null && is_string($envelope['subject_label'] ?? null)) {
            $subjectLabel = $envelope['subject_label'];
        }

        $actor = $this->currentActor();

        if ($issued === null) {
            return $this->issueOriginal(
                $template, $subjectType, $subjectId, $subjectLabel, $snapshotId,
                $snapshot, $chrome, $lang, $bulkPrintJobId, $seriesScopeValue,
                $supersedesIssuedDocumentId, $actor,
            );
        }

        // ---- Reprint path (4.5). ------------------------------------------
        Gate::authorize(Permission::DocumentsReprint->value);

        // The language was PINNED at issue (4.4's language column): a school
        // that later flips its default must still reproduce the issued
        // artefact byte for byte.
        $lang = DocumentLanguage::from($issued->language);

        if ($this->isFinancial($template)) {
            Gate::authorize(Permission::DocumentsReprintFinancial->value);
        }

        $stamp = new PdfStamp(
            $issued->issued_at->format('YmdHis'),
            $this->stampSeed($template, $subjectType, $subjectId, $snapshotId, $issued->serial),
        );

        // Re-render the CLEAN artefact from the pinned version + snapshot and
        // compare hashes before anything else happens.
        $cleanHtml = $this->renderHtml($template, $issued->template_version, $issued->serial, $lang, $chrome, $snapshot['payload'], $subjectLabel, [
            'watermark' => null,
            'issued_at' => $issued->issued_at,
            'copy_no' => 1,
        ]);
        $cleanBytes = $this->pdf->render($cleanHtml, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));
        $hash = hash('sha256', $cleanBytes);

        if (! hash_equals($issued->content_hash, $hash)) {
            throw DocumentReproducibilityViolation::forSerial($issued->serial, $issued->content_hash, $hash);
        }

        // Backfill for documents issued before payload freezing existed.
        // Reaching this line means the payload just re-rendered to the SAME
        // bytes recorded at issue, so it IS the original artefact's payload
        // and freezing it now is safe - it protects this document from the
        // next legitimate correction to its source rows, which is exactly
        // what would otherwise strand it as unreprintable. Only ever
        // NULL -> value (IssuedDocument's guard refuses anything else).
        if ($issued->payload_snapshot === null && $snapshot['payload'] !== []) {
            $issued->payload_snapshot = $snapshot['payload'];
            $issued->save();
        }

        // Same proof as the payload backfill directly above: control only
        // reaches here because the re-render reproduced the recorded hash
        // byte for byte, so this envelope IS the original artefact's. Only
        // ever NULL -> value; IssuedDocument's guard refuses anything else.
        if ($issued->render_envelope === null) {
            $issued->render_envelope = ['subject_label' => $subjectLabel, 'school' => $chrome];
            $issued->save();
        }

        // is_duplicate: derived, in-transaction, under the row lock (4.5).
        $priorPrints = DocumentPrintLog::query()
            ->where('issued_document_id', $issued->getKey())
            ->count();

        $isDuplicate = $priorPrints > 0;
        $copyNo = $priorPrints + 1;

        // Precedence: void > duplicata > specimen. A revoked or duplicate
        // document is the more urgent thing to say, and only one watermark
        // can be drawn; specimen is the weakest claim of the three.
        $watermark = $issued->isRevoked()
            ? 'void'
            : ($isDuplicate ? 'duplicata' : $this->provisionalWatermark());

        $outputHtml = $this->renderHtml($template, $issued->template_version, $issued->serial, $lang, $chrome, $snapshot['payload'], $subjectLabel, [
            'watermark' => $watermark,
            'issued_at' => $issued->issued_at,
            'copy_no' => $copyNo,
            'printed_on' => Carbon::now()->format('d/m/Y'),
            'printed_by_name' => $actor->name,
        ]);
        $outputBytes = $watermark === null
            ? $cleanBytes
            : $this->pdf->render($outputHtml, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));

        $log = $this->writePrintLog(
            $template, $issued->template_version, (int) $issued->getKey(),
            $subjectType, $subjectId, $subjectLabel, $snapshot['version'],
            $isDuplicate, $copyNo, $lang, $bulkPrintJobId, $actor,
        );

        $path = $this->store($template, $issued->serial, $outputBytes, $copyNo);

        return new RenderedDocument(
            bytes: $outputBytes,
            html: $outputHtml,
            contentHash: $hash,
            language: $lang,
            isDuplicate: $isDuplicate,
            copyNo: $copyNo,
            serial: $issued->serial,
            issuedDocumentId: (int) $issued->getKey(),
            printLogId: (int) $log->getKey(),
            storagePath: $path,
        );
    }

    /**
     * @param  array{payload: array<string, mixed>, version: int|null}  $snapshot
     * @param  array<string, mixed>  $chrome
     */
    private function issueOriginal(
        DocumentTemplate $template,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        int $snapshotId,
        array $snapshot,
        array $chrome,
        DocumentLanguage $lang,
        ?int $bulkPrintJobId,
        ?string $seriesScopeValue,
        ?int $supersedesIssuedDocumentId,
        Actor $actor,
    ): RenderedDocument {
        // 5. Series allocation - INSIDE the transaction, from the row-locked
        // sequence, never max()+1 (00-core 12). A render failure below rolls
        // this back: gaps are permitted but a failed render consumes nothing.
        $serial = null;
        $seriesCode = null;

        if ($template->series_code !== null) {
            /** @var DocumentSeries $series */
            $series = DocumentSeries::query()
                ->where('code', $template->series_code)
                ->where('is_active', true)
                ->firstOrFail();

            $scopeValue = $this->scopeValue($series, $seriesScopeValue, null);
            $number = app(SequenceAllocator::class)->allocate(
                'document.'.$series->code.($scopeValue === '' ? '' : '.'.$scopeValue),
            );

            $serial = SeriesFormatter::format(
                $series->format,
                $this->schoolShortCode(),
                $scopeValue,
                $series->code,
                $number,
                $series->padding,
            );
            $seriesCode = $series->code;
        }

        // issued_at is truncated to the second BEFORE stamping so the stored
        // value and the PDF CreationDate round-trip identically on reprint.
        $issuedAt = Carbon::now()->startOfSecond();
        $stamp = new PdfStamp(
            $issuedAt->format('YmdHis'),
            $this->stampSeed($template, $subjectType, $subjectId, $snapshotId, $serial),
        );

        // 6-7. Render and hash. The clean render IS the original artefact.
        $html = $this->renderHtml($template, $template->version, $serial, $lang, $chrome, $snapshot['payload'], $subjectLabel, [
            'watermark' => null,
            'issued_at' => $issuedAt,
            'copy_no' => 1,
        ]);
        $bytes = $this->pdf->render($html, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));
        $hash = hash('sha256', $bytes);

        // SPECIMEN is an OUTPUT overlay, never part of the hashed artefact -
        // exactly how duplicata behaves on reprint. Hashing the clean render
        // is what lets a document issued while provisional still reprint
        // byte-identically after the identity is confirmed: the stored hash
        // describes the document, and the watermark describes the conditions
        // it was printed under. Folding the watermark into the hash would
        // make confirming the NIU retroactively fail every prior document's
        // reproducibility check (§4.5).
        $outputBytes = $bytes;

        if ($this->provisionalWatermark() !== null) {
            $specimenHtml = $this->renderHtml($template, $template->version, $serial, $lang, $chrome, $snapshot['payload'], $subjectLabel, [
                'watermark' => 'specimen',
                'issued_at' => $issuedAt,
                'copy_no' => 1,
            ]);
            $outputBytes = $this->pdf->render($specimenHtml, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));
        }

        // 8. IssuedDocument + DocumentPrintLog.
        if ($actor->id === null) {
            throw new DomainException('Issuing a document requires an authenticated actor (00-core 14).');
        }

        /** @var IssuedDocument $issued */
        $issued = IssuedDocument::query()->create([
            'document_template_id' => $template->getKey(),
            'template_version' => $template->version,
            'series_code' => $seriesCode,
            'serial' => $serial,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'snapshot_type' => $template->snapshot_source ?? $subjectType,
            'snapshot_id' => $snapshotId,
            'payload_snapshot' => ($template->snapshot_source !== null && SnapshotSourceMap::has($template->snapshot_source))
                ? null
                : $snapshot['payload'],
            // Frozen for EVERY snapshot-backed issue, mapped or not. Receipt-
            // pattern payloads already carry `school`, so for them this half
            // is belt-and-braces - but `subject_label` is re-derived live for
            // them too, and one unbranched write is less to get wrong than a
            // condition that has already been got wrong once.
            'render_envelope' => ['subject_label' => $subjectLabel, 'school' => $chrome],
            'language' => $lang->value,
            'content_hash' => $hash,
            'qr_token' => null, // D2 wires the OPES1 signing stack (10-documents 17).
            'issued_by' => $actor->id,
            'issued_at' => $issuedAt,
            'issued_by_name_at_time' => $actor->name,
            'status' => IssuedDocument::STATUS_VALID,
        ]);

        if ($supersedesIssuedDocumentId !== null) {
            /** @var IssuedDocument $prior */
            $prior = IssuedDocument::query()->lockForUpdate()->findOrFail($supersedesIssuedDocumentId);
            $prior->status = IssuedDocument::STATUS_SUPERSEDED;
            $prior->superseded_by_document_id = (int) $issued->getKey();
            $prior->save();
        }

        $log = $this->writePrintLog(
            $template, $template->version, (int) $issued->getKey(),
            $subjectType, $subjectId, $subjectLabel, $snapshot['version'],
            false, 1, $lang, $bulkPrintJobId, $actor,
        );

        // Stores and returns the OUTPUT bytes - what the operator actually
        // receives, carrying SPECIMEN when provisional. `$hash` above stays
        // the clean artefact's, which is what reprint verifies against.
        $path = $this->store($template, $serial, $outputBytes, 1);

        return new RenderedDocument(
            bytes: $outputBytes,
            html: $html,
            contentHash: $hash,
            language: $lang,
            isDuplicate: false,
            copyNo: 1,
            serial: $serial,
            issuedDocumentId: (int) $issued->getKey(),
            printLogId: (int) $log->getKey(),
            storagePath: $path,
        );
    }

    // -----------------------------------------------------------------------
    // Live path
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderLive(
        DocumentTemplate $template,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        DocumentLanguage $lang,
        ?int $schoolSectionId,
        array $data,
        ?int $bulkPrintJobId,
    ): RenderedDocument {
        $chrome = $this->schoolChrome($template, $schoolSectionId, $data);
        $actor = $this->currentActor();
        $generatedAt = Carbon::now()->startOfSecond();

        // Live documents are working views: no series, no IssuedDocument,
        // no byte-identity - and a footer that SAYS so (4.2). There is no
        // hash to protect here, so the specimen overlay simply goes on the
        // one and only render.
        $html = $this->renderHtml($template, $template->version, null, $lang, $chrome, $data, $subjectLabel, [
            'watermark' => $this->provisionalWatermark(),
            'issued_at' => null,
            'generated_at' => $generatedAt,
            'generated_by' => $actor->name,
            'copy_no' => 1,
        ]);

        $stamp = new PdfStamp(
            $generatedAt->format('YmdHis'),
            $this->stampSeed($template, $subjectType, $subjectId, null, null).'|'.$generatedAt->format('YmdHis'),
        );
        $bytes = $this->pdf->render($html, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));

        $log = $this->writePrintLog(
            $template, $template->version, null,
            $subjectType, $subjectId, $subjectLabel, null,
            false, 1, $lang, $bulkPrintJobId, $actor,
        );

        $path = $this->store($template, null, $bytes, 1);

        return new RenderedDocument(
            bytes: $bytes,
            html: $html,
            contentHash: hash('sha256', $bytes),
            language: $lang,
            isDuplicate: false,
            copyNo: 1,
            serial: null,
            issuedDocumentId: null,
            printLogId: (int) $log->getKey(),
            storagePath: $path,
        );
    }

    // -----------------------------------------------------------------------
    // Pipeline pieces
    // -----------------------------------------------------------------------

    private function resolveLanguage(?string $language, ?int $schoolSectionId): DocumentLanguage
    {
        if ($language !== null) {
            $explicit = DocumentLanguage::tryFrom($language);

            if ($explicit === null) {
                throw ValidationException::withMessages([
                    'language' => "Document language [{$language}] is not en or fr (10-documents 4.6).",
                ]);
            }

            return $explicit;
        }

        if ($schoolSectionId !== null) {
            $sectionLanguage = DB::table('school_sections')
                ->where('id', $schoolSectionId)
                ->value('document_language');

            if (is_string($sectionLanguage) && DocumentLanguage::tryFrom($sectionLanguage) !== null) {
                return DocumentLanguage::from($sectionLanguage);
            }
        }

        $default = DB::table('school_document_profiles')->where('id', 1)->value('default_document_language');

        return is_string($default) && DocumentLanguage::tryFrom($default) !== null
            ? DocumentLanguage::from($default)
            : DocumentLanguage::En;
    }

    /**
     * The chrome every document shares: state header, school identity,
     * fiscal identity, branding.
     *
     * Precedence, strongest first: the envelope frozen onto the issued
     * document at issue; then a `school` block the snapshot payload captured
     * itself (the receipt pattern); then a live read. Anything but the live
     * read is what makes a years-later reprint carry the letterhead AS AT
     * ISSUE rather than today's - which is the difference between a reprint
     * and a forgery, and, because the chrome is inside the hashed bytes,
     * between a reprint and a permanent 422.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{subject_label?: string, school?: array<string, mixed>}|null  $envelope
     * @return array<string, mixed>
     */
    private function schoolChrome(DocumentTemplate $template, ?int $schoolSectionId, array $payload, ?array $envelope = null): array
    {
        if (is_array($envelope['school'] ?? null)) {
            /** @var array<string, mixed> $frozen */
            $frozen = $envelope['school'];

            return $frozen;
        }

        if (isset($payload['school']) && is_array($payload['school'])) {
            /** @var array<string, mixed> $captured */
            $captured = $payload['school'];

            return $captured;
        }

        return $this->captureSchoolChrome($template->state_header !== 'none', $schoolSectionId);
    }

    /**
     * The public half of schoolChrome(): callers OUTSIDE this pipeline that
     * assemble their own snapshot payload (phase-12-13 D3's "receipt
     * pattern" - Fees/Tax/Procurement Actions whose subject already has its
     * OWN domain number and therefore renders with no registered
     * SnapshotSourceMap entry) call this to capture the AS-AT-ISSUE `school`
     * block themselves and embed it under `data['school']`, so their
     * snapshot-backed template gets the same byte-identity guarantee (4.2)
     * as a registered source without duplicating this read in every module.
     *
     * @return array<string, mixed>
     */
    public function captureSchoolChrome(bool $includeStateHeader, ?int $schoolSectionId = null): array
    {
        $profile = DB::table('school_document_profiles')->where('id', 1)->first();
        $fiscal = DB::table('fiscal_identities')->where('id', 1)->first();

        $stateHeader = null;

        if ($includeStateHeader && $profile !== null && (bool) $profile->state_header_enabled) {
            $stateHeader = [
                'ministry_en' => $profile->ministry_en,
                'ministry_fr' => $profile->ministry_fr,
                'regional_delegation_en' => $profile->regional_delegation_en,
                'regional_delegation_fr' => $profile->regional_delegation_fr,
                'divisional_delegation_en' => $profile->divisional_delegation_en,
                'divisional_delegation_fr' => $profile->divisional_delegation_fr,
            ];

            // 2.1: per-section ministry override - a bilingual school's
            // MINEDUB nursery and MINESEC secondary sit under different
            // ministries.
            if ($schoolSectionId !== null) {
                $section = DB::table('school_sections')->where('id', $schoolSectionId)->first();

                if ($section !== null && is_string($section->state_header_ministry_en ?? null)) {
                    $stateHeader['ministry_en'] = $section->state_header_ministry_en;
                }

                if ($section !== null && is_string($section->state_header_ministry_fr ?? null)) {
                    $stateHeader['ministry_fr'] = $section->state_header_ministry_fr;
                }
            }
        }

        return [
            'name' => app(ReadSetting::class)->handle('school.name', ''),
            'name_fr' => app(ReadSetting::class)->handle('school.name_fr', ''),
            'short_code' => $this->schoolShortCode(),
            'state_header' => $stateHeader,
            'branding' => $profile === null ? [] : [
                'crest_path' => $profile->crest_path,
                'logo_path' => $profile->logo_path,
                'principal_signature_path' => $profile->principal_signature_path,
                'registrar_signature_path' => $profile->registrar_signature_path,
                'school_stamp_path' => $profile->school_stamp_path,
            ],
            // Letterhead contacts (10-documents §4.7). Ordinary school-supplied
            // details, not registry-issued identifiers like the NIU below, so
            // they carry none of the §2 verification requirements. Every one is
            // nullable and the header block prints only what it is given.
            'contacts' => $profile === null ? [] : [
                'address_line1' => $profile->address_line1 ?? null,
                'address_line2' => $profile->address_line2 ?? null,
                'city' => $profile->city ?? null,
                'region' => $profile->region ?? null,
                'po_box' => $profile->po_box ?? null,
                'phone' => $profile->phone ?? null,
                'phone_alt' => $profile->phone_alt ?? null,
                'email' => $profile->email ?? null,
                'website' => $profile->website ?? null,
                'authorisation_line' => $profile->authorisation_line ?? null,
            ],
            'fiscal' => $fiscal === null ? null : [
                'niu' => $fiscal->niu,
                'rccm_number' => $fiscal->rccm_number,
                'legal_name' => $fiscal->legal_name,
            ],
            'bilingual' => $profile !== null && (bool) $profile->bilingual_documents,
        ];
    }

    /**
     * @param  array<string, mixed>  $callerData
     * @return array{payload: array<string, mixed>, version: int|null}
     */
    private function loadSnapshot(DocumentTemplate $template, int $snapshotId, array $callerData, ?IssuedDocument $issued): array
    {
        $source = $template->snapshot_source;

        if ($source !== null && SnapshotSourceMap::has($source)) {
            $map = SnapshotSourceMap::get($source);

            $row = DB::table($map['table'])->where('id', $snapshotId)->first();

            if ($row === null) {
                throw ValidationException::withMessages([
                    'snapshot_id' => "Snapshot {$snapshotId} does not exist in [{$map['table']}]; "
                        .'a snapshot-backed document renders only from an existing immutable payload (10-documents 4.2).',
                ]);
            }

            /** @var mixed $decoded */
            $decoded = json_decode((string) $row->{$map['payload']}, true);
            $version = $map['version'] !== null && is_numeric($row->{$map['version']} ?? null)
                ? (int) $row->{$map['version']}
                : null;

            return [
                'payload' => is_array($decoded) ? $decoded : [],
                'version' => $version,
            ];
        }

        // The receipt pattern: the immutable "snapshot" is the append-only
        // row pair the calling Action read; the payload it assembled from
        // those rows is the render input, and the content-hash comparison on
        // reprint is what holds that Action to determinism.
        //
        // On a reprint, re-deriving that payload from live tables is exactly
        // the bug this freezes: a legitimate later correction (a renamed
        // student, a re-allocated payment) changes what gets re-derived,
        // which then permanently breaks reprintability with a
        // DocumentReproducibilityViolation. Once a payload was frozen at
        // issue time, read it back instead of re-deriving it.
        if ($issued !== null && $issued->payload_snapshot !== null) {
            return ['payload' => $issued->payload_snapshot, 'version' => null];
        }

        return ['payload' => $callerData, 'version' => null];
    }

    private function scopeValue(DocumentSeries $series, ?string $override, ?int $schoolSectionId): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $today = BusinessDate::today();

        return match ($series->scope) {
            'global' => '',
            'academic_year', 'fiscal_year' => substr($today, 0, 4),
            'day' => $today,
            'payroll_month' => substr($today, 0, 7),
            'section' => (string) ($schoolSectionId ?? 0),
            default => '',
        };
    }

    /**
     * 10-documents §19's money templates whose OWN domain module already
     * allocated the printed number before this platform existed (the
     * "receipt pattern" - phase-12-13 D3: Fees' receipt_no/invoice_no,
     * Procurement's payment_no, Tax's attestation_no) carry series_code =
     * NULL by design (see the 310010 seed migration), so they cannot be
     * recognised by DocumentSeries::FINANCIAL_SERIES membership alone. Their
     * template CODES are recognised directly here - the SAME
     * documents.reprint_financial gate a series-numbered financial document
     * gets, just keyed differently because there is no series to key off.
     */
    private const FINANCIAL_TEMPLATE_CODES = [
        'FEE-RECEIPT', 'FEE-RECEIPT-POS', 'FEE-INVOICE', 'WHT-CERT', 'PAY-VOUCHER',
    ];

    /**
     * 'specimen' while the school's fiscal identity is provisional, otherwise
     * null (10-documents §4.7 - "SPECIMEN for previews and demo licences").
     *
     * Returned as the watermark VALUE rather than a boolean so every call
     * site reads as the watermark decision it is, and so the three render
     * paths cannot disagree about the string.
     */
    private function provisionalWatermark(): ?string
    {
        return FiscalIdentityGate::isProvisional() ? 'specimen' : null;
    }

    private function isFinancial(DocumentTemplate $template): bool
    {
        return ($template->series_code !== null
                && in_array($template->series_code, DocumentSeries::FINANCIAL_SERIES, true))
            || in_array($template->code, self::FINANCIAL_TEMPLATE_CODES, true);
    }

    private function schoolShortCode(): string
    {
        /** @var mixed $code */
        $code = app(ReadSetting::class)->handle('school.short_code', 'SCH');

        return is_string($code) && $code !== '' ? $code : 'SCH';
    }

    /**
     * @param  array<string, mixed>  $chrome
     * @param  array<string, mixed>  $payload
     * @param  array{watermark: string|null, issued_at: Carbon|null, copy_no: int, generated_at?: Carbon, generated_by?: string, printed_on?: string, printed_by_name?: string}  $renderState
     */
    private function renderHtml(
        DocumentTemplate $template,
        int $templateVersion,
        ?string $serial,
        DocumentLanguage $lang,
        array $chrome,
        array $payload,
        string $subjectLabel,
        array $renderState,
    ): string {
        $generatedAt = $renderState['generated_at'] ?? null;

        return view($template->blade_view, [
            'document' => [
                'template_code' => $template->code,
                'template_version' => $templateVersion,
                'serial' => $serial,
                'language' => $lang->value,
                'watermark' => $renderState['watermark'],
                'is_duplicate' => ($renderState['watermark'] ?? null) === 'duplicata',
                'copy_no' => $renderState['copy_no'],
                'issued_at' => $renderState['issued_at']?->format('d/m/Y'),
                'generated_at' => $generatedAt?->format('d/m/Y H:i'),
                'generated_by' => $renderState['generated_by'] ?? null,
                'printed_on' => $renderState['printed_on'] ?? null,
                'printed_by_name' => $renderState['printed_by_name'] ?? null,
                'signature_roles' => $template->signature_roles ?? [],
                'carries_qr' => $template->carries_qr,
                'qr_token' => null,
            ],
            'school' => $chrome,
            'payload' => $payload,
            'subject' => ['label' => $subjectLabel],
        ])->render();
    }

    private function pageFooter(DocumentLanguage $lang): string
    {
        /** @var string $text */
        $text = __('documents.footer.page', [], $lang->value);

        return $text;
    }

    private function stampSeed(
        DocumentTemplate $template,
        string $subjectType,
        int $subjectId,
        ?int $snapshotId,
        ?string $serial,
    ): string {
        return implode('|', [
            $template->code,
            $subjectType,
            (string) $subjectId,
            $snapshotId === null ? '-' : (string) $snapshotId,
            $serial ?? '-',
        ]);
    }

    private function writePrintLog(
        DocumentTemplate $template,
        int $templateVersion,
        ?int $issuedDocumentId,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        ?int $snapshotVersion,
        bool $isDuplicate,
        int $copyNo,
        DocumentLanguage $lang,
        ?int $bulkPrintJobId,
        Actor $actor,
    ): DocumentPrintLog {
        if ($actor->id === null) {
            throw new DomainException('Printing a document requires an authenticated actor (00-core 14).');
        }

        /** @var DocumentPrintLog $log */
        $log = DocumentPrintLog::query()->create([
            'document_template_id' => $template->getKey(),
            'template_version' => $templateVersion,
            'issued_document_id' => $issuedDocumentId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label_at_time' => mb_substr($subjectLabel, 0, 200),
            'snapshot_version' => $snapshotVersion,
            'is_duplicate' => $isDuplicate,
            'copy_no' => $copyNo,
            'language' => $lang->value,
            'paper_size' => $template->paper_size,
            'bulk_print_job_id' => $bulkPrintJobId,
            'printed_by' => $actor->id,
            'actor_name_at_time' => $actor->name,
            'printed_at' => Carbon::now(),
            'ip' => request()->ip(),
        ]);

        return $log;
    }

    /**
     * 4.8 `GeneratedDocumentPath`: storage/documents/{yyyy}/{module}/{template}/{serial|uuid}.pdf.
     */
    private function store(DocumentTemplate $template, ?string $serial, string $bytes, int $copyNo): string
    {
        $name = $serial === null
            ? (string) Str::uuid()
            : DocumentFileName::sanitize($serial).($copyNo > 1 ? '-copy'.$copyNo : '');

        $relative = sprintf(
            'documents/%s/%s/%s/%s.pdf',
            substr(BusinessDate::today(), 0, 4),
            DocumentFileName::sanitize($template->module),
            DocumentFileName::sanitize($template->code),
            $name,
        );

        $absolute = storage_path($relative);
        $dir = dirname($absolute);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absolute, $bytes);

        return $relative;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
