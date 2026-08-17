<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Domain\WhatsAppDeliveryStatus;
use App\Modules\Communication\Domain\WhatsAppMessageType;
use App\Support\Retention\Immutable10Year;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * `whatsapp_delivery_logs` (2026_08_16_600001) - the evidence that a parent
 * was, or was not, messaged.
 *
 * APPEND-ONLY, and it carries Immutable10Year. That choice needs stating,
 * because this is not an accounting book and most of the trait's 28 users
 * are: the precedent followed here is Reporting's DocumentPrintLog, which is
 * likewise an operational record of "this went out of the building" rather
 * than a statutory ledger. The argument is the same one. This log's whole
 * purpose is to settle a later dispute with a parent ("we were never told"),
 * so a record that could be edited, or that a routine cleanup could delete,
 * would be worth nothing at exactly the moment it is produced - and those
 * disputes surface years afterwards, over a child's whole time at the
 * school, which is the horizon the trait already encodes. A row is
 * therefore writable once and undeletable until it is ten years old.
 *
 * @property int $id
 * @property int|null $guardian_id
 * @property int|null $outbox_message_id
 * @property string $recipient_phone
 * @property WhatsAppMessageType $message_type
 * @property string|null $template_name
 * @property string|null $template_language
 * @property string|null $provider_message_id
 * @property WhatsAppDeliveryStatus $status
 * @property int|null $error_code
 * @property string|null $error_message
 * @property int|null $http_status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WhatsAppDeliveryLog extends Model
{
    use Immutable10Year;

    /**
     * Explicit, because Eloquent's convention does NOT produce the right
     * name here: it snake-cases the class to `whats_app_delivery_logs`,
     * splitting "WhatsApp" at its internal capital. Without this the model
     * silently addresses a table that does not exist.
     */
    protected $table = 'whatsapp_delivery_logs';

    /** @var list<string> */
    protected $fillable = [
        'guardian_id', 'outbox_message_id', 'recipient_phone', 'message_type',
        'template_name', 'template_language', 'provider_message_id', 'status',
        'error_code', 'error_message', 'http_status', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guardian_id' => 'integer',
            'outbox_message_id' => 'integer',
            'message_type' => WhatsAppMessageType::class,
            'status' => WhatsAppDeliveryStatus::class,
            'error_code' => 'integer',
            'http_status' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Deletion is Immutable10Year's business; this closes the other half.
        // An attempt happened or it did not - there is no later correction to
        // an observation, and a log whose rows can be rewritten cannot be
        // used as proof of anything.
        static::updating(static function (): void {
            throw new RuntimeException(
                'whatsapp_delivery_logs is append-only: a delivery attempt is an '
                .'observation and cannot be edited. Record a new attempt instead.'
            );
        });
    }

    /** Never render a full number in a list a whole office can see. */
    public function maskedPhone(): string
    {
        $phone = $this->recipient_phone;

        if (mb_strlen($phone) <= 4) {
            return $phone;
        }

        return str_repeat('*', mb_strlen($phone) - 4).mb_substr($phone, -4);
    }
}
