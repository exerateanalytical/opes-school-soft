<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\AmountInWords;
use App\Modules\Reporting\Domain\DocumentLanguage;

/*
 * docs/specs/10-documents.md 4.6 - the golden tables. Each language is an
 * independent implementation pinned by its own table: the spec's mandatory
 * values (0, 1, 21, 71, 80, 81, 100, 200, 1 000, 80 000, 1 000 000), every
 * value in the fee-fixture set (200 000 + 150 000 = 350 000, feesFixture in
 * tests/Feature/Fees/InvoiceTest.php), and the traps that catch a wrong
 * French agreement: the terminal s on "cents"/"quatre-vingts", its loss
 * before "mille", its survival before "millions".
 */

it('renders the French golden table with the Cameroonian quatre-vingts forms', function (int $amount, string $expected) {
    expect(AmountInWords::fr($amount))->toBe($expected);
})->with([
    [0, 'zéro'],
    [1, 'un'],
    [17, 'dix-sept'],
    [21, 'vingt et un'],
    [70, 'soixante-dix'],
    [71, 'soixante et onze'],
    [77, 'soixante-dix-sept'],
    [80, 'quatre-vingts'],
    [81, 'quatre-vingt-un'],
    [90, 'quatre-vingt-dix'],
    [91, 'quatre-vingt-onze'],
    [99, 'quatre-vingt-dix-neuf'],
    [100, 'cent'],
    [101, 'cent un'],
    [180, 'cent quatre-vingts'],
    [200, 'deux cents'],
    [201, 'deux cent un'],
    [1_000, 'mille'],
    [1_001, 'mille un'],
    [2_000, 'deux mille'],
    [80_000, 'quatre-vingt mille'],
    [80_080, 'quatre-vingt mille quatre-vingts'],
    [100_000, 'cent mille'],
    // the fee-fixture set (two structure lines and their invoice total)
    [150_000, 'cent cinquante mille'],
    [200_000, 'deux cent mille'],
    [350_000, 'trois cent cinquante mille'],
    [1_000_000, 'un million'],
    [1_234_500, 'un million deux cent trente-quatre mille cinq cents'],
    [2_000_000, 'deux millions'],
    // the noun rule: "cents"/"quatre-vingts" KEEP their s before millions
    [80_000_000, 'quatre-vingts millions'],
    [200_000_000, 'deux cents millions'],
    [1_000_000_000, 'un milliard'],
    [999_999_999_999, 'neuf cent quatre-vingt-dix-neuf milliards neuf cent quatre-vingt-dix-neuf millions neuf cent quatre-vingt-dix-neuf mille neuf cent quatre-vingt-dix-neuf'],
]);

it('renders the English golden table in short scale', function (int $amount, string $expected) {
    expect(AmountInWords::en($amount))->toBe($expected);
})->with([
    [0, 'zero'],
    [1, 'one'],
    [17, 'seventeen'],
    [21, 'twenty-one'],
    [71, 'seventy-one'],
    [80, 'eighty'],
    [81, 'eighty-one'],
    [100, 'one hundred'],
    [101, 'one hundred one'],
    [200, 'two hundred'],
    [1_000, 'one thousand'],
    [80_000, 'eighty thousand'],
    // the fee-fixture set
    [150_000, 'one hundred fifty thousand'],
    [200_000, 'two hundred thousand'],
    [350_000, 'three hundred fifty thousand'],
    [1_000_000, 'one million'],
    [1_234_500, 'one million two hundred thirty-four thousand five hundred'],
    [1_000_000_000, 'one billion'],
    [2_500_000_001, 'two billion five hundred million one'],
    [999_999_999_999, 'nine hundred ninety-nine billion nine hundred ninety-nine million nine hundred ninety-nine thousand nine hundred ninety-nine'],
]);

it('routes render() through the document language', function () {
    expect(AmountInWords::render(80, DocumentLanguage::Fr))->toBe('quatre-vingts')
        ->and(AmountInWords::render(80, DocumentLanguage::En))->toBe('eighty');
});

it('refuses a negative amount rather than print a wrong legal amount', function () {
    AmountInWords::fr(-1);
})->throws(InvalidArgumentException::class);

it('refuses an amount beyond the writable range', function () {
    AmountInWords::en(1_000_000_000_000);
})->throws(InvalidArgumentException::class);
