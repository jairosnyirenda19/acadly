<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free numbers, one series per kind per year: RCT-2028-000530. A number
 * is taken inside the transaction that keeps the record, under a row lock,
 * so two clerks never get the same number and a refused save leaves no gap.
 */
final class Numbers
{
    public const string RECEIPT = 'RCT';

    public const string BILL = 'BIL';

    public const string ADJUSTMENT = 'ADJ';

    public const string VOID = 'VOID';

    public static function next(string $scope, string $day): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A number is taken only inside the transaction that keeps the record.');
        }

        $year = (int) substr($day, 0, 4);
        DB::table('fee_sequences')->insertOrIgnore(['scope' => $scope, 'year' => $year, 'next_number' => 1]);
        $row = DB::table('fee_sequences')->where('scope', $scope)->where('year', $year)->lockForUpdate()->first(['next_number']);
        $number = $row === null ? 1 : Row::int($row, 'next_number');
        DB::table('fee_sequences')->where('scope', $scope)->where('year', $year)->update(['next_number' => $number + 1]);

        return sprintf('%s-%d-%06d', $scope, $year, $number);
    }
}
