<?php

declare(strict_types=1);

final class CalendarEventBuilder
{
    /**
     * @param array<string, mixed> $appointmentData
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $booking
     * @return array<string, mixed>|null
     */
    public static function build(array $appointmentData, array $settings, array $booking): ?array
    {
        $from = $appointmentData['from'] ?? null;
        $to = $appointmentData['to'] ?? null;

        if (!is_numeric($from) || !is_numeric($to)) {
            return null;
        }

        $tz = new DateTimeZone('Asia/Tehran');
        $startDt = (new DateTimeImmutable('@' . (int) $from))->setTimezone($tz);
        $endDt = (new DateTimeImmutable('@' . (int) $to))->setTimezone($tz);
        $start = $startDt->format('c');
        $end = $endDt->format('c');

        $patientName = self::stringValue($booking, 'patient_name');
        $patientFamily = self::stringValue($booking, 'patient_family');
        $centerName = self::stringValue($booking, 'center_name');
        $patientCell = self::stringValue($booking, 'patient_cell');
        $patientNationalCode = self::stringValue($booking, 'patient_national_code');

        $showPatientName = GoogleTokensRepository::toBoolPublic($settings['Patient_name'] ?? false);
        $showCenter = GoogleTokensRepository::toBoolPublic($settings['Patient_center'] ?? false);
        $showDateTime = GoogleTokensRepository::toBoolPublic($settings['Patient_date_time'] ?? false);
        $showPhone = GoogleTokensRepository::toBoolPublic($settings['Patient_phone'] ?? false);
        $showNational = GoogleTokensRepository::toBoolPublic($settings['Patient_national'] ?? false);
        $colorId = (string) ($settings['color_id'] ?? '9');

        if (str_contains($centerName, 'ویزیت آنلاین')) {
            $summary = 'ویزیت آنلاین پذیرش 24';
        } elseif ($showPatientName) {
            $summary = 'نام بیمار : ' . trim($patientName . ' ' . $patientFamily);
        } else {
            $summary = 'نوبت پذیرش 24';
        }

        $descriptionParts = [];

        if ($showPatientName) {
            $descriptionParts[] = 'بیمار : ' . trim($patientName . ' ' . $patientFamily);
        }

        if ($showCenter && $centerName !== '') {
            $descriptionParts[] = 'مرکز : ' . $centerName;
        }

        if ($showDateTime) {
            $descriptionParts[] = 'ساعت نوبت : ' . $startDt->format('H:i')
                . "\n" . 'تاریخ : ' . self::formatPersianDateTime($startDt);
        }

        if ($showPhone) {
            $descriptionParts[] = 'شماره تلفن : ' . $patientCell;
        }

        if ($showNational) {
            $descriptionParts[] = 'کد ملی : ' . $patientNationalCode;
        }

        $bookId = self::stringValue($booking, 'book_id');
        if ($bookId !== '') {
            $descriptionParts[] = $bookId;
        }

        $event = [
            'summary' => $summary,
            'description' => implode("\n", $descriptionParts),
            'colorId' => $colorId,
            'start' => [
                'dateTime' => $start,
                'timeZone' => 'Asia/Tehran',
            ],
            'end' => [
                'dateTime' => $end,
                'timeZone' => 'Asia/Tehran',
            ],
        ];

        if ($bookId !== '') {
            $privateProps = [
                'hamgam_book_id' => $bookId,
                'hamgam_source' => 'paziresh24',
            ];

            $centerId = BookingAppointmentResolver::extractCenterId($booking);
            if ($centerId !== null && $centerId !== '') {
                $privateProps['hamgam_center_id'] = $centerId;
            }

            $event['extendedProperties'] = [
                'private' => $privateProps,
            ];
        }

        return $event;
    }

    private static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    private static function formatPersianDateTime(DateTimeImmutable $dt): string
    {
        [$jy, $jm, $jd] = self::gregorianToJalali(
            (int) $dt->format('Y'),
            (int) $dt->format('n'),
            (int) $dt->format('j')
        );

        $monthNames = [
            1 => 'فروردین',
            2 => 'اردیبهشت',
            3 => 'خرداد',
            4 => 'تیر',
            5 => 'مرداد',
            6 => 'شهریور',
            7 => 'مهر',
            8 => 'آبان',
            9 => 'آذر',
            10 => 'دی',
            11 => 'بهمن',
            12 => 'اسفند',
        ];

        $weekdayNames = [
            'شنبه',
            'یکشنبه',
            'دوشنبه',
            'سه‌شنبه',
            'چهارشنبه',
            'پنجشنبه',
            'جمعه',
        ];

        $weekdayIndex = ((int) $dt->format('w') + 1) % 7;
        $monthName = $monthNames[$jm] ?? (string) $jm;

        return self::toPersianDigits(sprintf('%d %s %d', $jd, $monthName, $jy))
            . '، '
            . $weekdayNames[$weekdayIndex];
    }

    private static function toPersianDigits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $value
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy)
            + (int) (($gy2 + 3) / 4)
            - (int) (($gy2 + 99) / 100)
            + (int) (($gy2 + 399) / 400)
            - 80
            + $gd;

        for ($i = 0; $i < $gm - 1; $i++) {
            $days += $gDaysInMonth[$i];
        }

        $jy += 33 * (int) ($days / 12053);
        $days %= 12053;
        $jy += 4 * (int) ($days / 1461);
        $days %= 1461;
        $jy += (int) (($days - 1) / 365);

        if ($days > 365) {
            $days = ($days - 1) % 365;
        }

        $jm = ($days < 186) ? 1 + (int) ($days / 31) : 7 + (int) (($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

        return [$jy, $jm, $jd];
    }
}
