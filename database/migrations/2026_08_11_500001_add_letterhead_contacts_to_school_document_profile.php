<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letterhead contact details (10-documents §4.7 school_header).
 *
 * The school_header block's own docblock has always promised "crest, school
 * name (EN/FR), contacts and the FISCAL IDENTITY line" - but there was
 * nowhere in the schema to PUT the contacts, so the block rendered a crest,
 * a name and a fiscal line and silently dropped the middle third. A
 * letterhead with no address or phone number does not read as a real
 * institutional document, which is the whole point of the block.
 *
 * These are ordinary school-supplied contact details, deliberately NOT the
 * same class of value as the NIU/RCCM in `fiscal_identities`: nothing here
 * is issued by a government registry, so a school can fill it in itself
 * without any of the verification 03-tax-procurement §2 demands. Every
 * column is nullable - a school that has not filled these in gets a
 * letterhead without them rather than a blank or invented one (00-core §16:
 * a wrong seeded value is more dangerous than an empty field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->string('address_line1', 160)->nullable()->after('bilingual_documents');
            $table->string('address_line2', 160)->nullable()->after('address_line1');
            $table->string('city', 80)->nullable()->after('address_line2');
            $table->string('region', 80)->nullable()->after('city');
            $table->string('po_box', 40)->nullable()->after('region');
            $table->string('phone', 60)->nullable()->after('po_box');
            $table->string('phone_alt', 60)->nullable()->after('phone');
            $table->string('email', 190)->nullable()->after('phone_alt');
            $table->string('website', 190)->nullable()->after('email');
            // Free-text authorisation line, e.g. an arrete/authorisation
            // number a private school prints under its name. Left as text
            // because its shape differs per school and per ministry, and
            // guessing a format would be worse than accepting theirs.
            $table->string('authorisation_line', 200)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'address_line1', 'address_line2', 'city', 'region', 'po_box',
                'phone', 'phone_alt', 'email', 'website', 'authorisation_line',
            ]);
        });
    }
};
