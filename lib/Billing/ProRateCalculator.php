<?php
declare(strict_types=1);

/**
 * lib/Billing/ProRateCalculator.php
 *
 * Pure math engine for pro-rated rental charges and mileage calculations.
 * No database access, no side effects — fully unit-testable.
 * All monetary parameters and returns are strings for bcmath precision (D16).
 *
 * Required by: lib/Billing/InvoiceGenerator.php
 * Defines: FleetForge\Billing\ProRateCalculator
 *
 * Decisions: D14 (inclusive day counting), D16 (bcmath only)
 * Spec ref: §9 The Pro-Rating Formula (THE LAW)
 */

namespace FleetForge\Billing;

class ProRateCalculator
{
    /**
     * Calculate the pro-rated rental charge for a billing period.
     *
     * THE LAW (spec §9):
     *   days <= 0  → $0, method='none'
     *   days 1–5   → days × daily_rate, method='daily'
     *   days 6–7   → weekly_rate flat, method='weekly'
     *   days 8–29  → weekly_math, but if weekly_math > monthly_rate → cap at monthly_rate
     *   days 30+   → monthly_math (full_months × monthly + remainder × monthly/30)
     *
     * @param int    $days    Number of billing days (inclusive: end - start + 1)
     * @param string $daily   Daily rate as string decimal
     * @param string $weekly  Weekly rate as string decimal
     * @param string $monthly Monthly rate as string decimal
     * @return array{amount: string, method: string, explanation: string[]}
     */
    public function calculate(int $days, string $daily, string $weekly, string $monthly): array
    {
        // Zero or negative days — no charge
        if ($days <= 0) {
            return [
                'amount'      => '0.00',
                'method'      => 'none',
                'explanation' => ['0 billable days — no charge'],
            ];
        }

        // 1–5 days: straight daily rate
        if ($days <= 5) {
            $amount = bcmul($daily, (string)$days, 6);
            $rounded = bcround($amount, 2);
            return [
                'amount'      => $rounded,
                'method'      => 'daily',
                'explanation' => ["{$days} days × \${$daily}/day = \${$rounded}"],
            ];
        }

        // 6–7 days: flat weekly rate
        if ($days <= 7) {
            $rounded = bcround($weekly, 2);
            return [
                'amount'      => $rounded,
                'method'      => 'weekly',
                'explanation' => ["{$days} days — weekly rate = \${$rounded}"],
            ];
        }

        // 8+ days: compute weekly math
        $fullWeeks = intdiv($days, 7);
        $remainingDays = $days % 7;
        // weekly_math = (full_weeks × weekly) + (remaining × (weekly / 7))
        $weeklyPart = bcmul($weekly, (string)$fullWeeks, 6);
        $dailyFromWeekly = bcdiv($weekly, '7', 6);
        $remainderPart = bcmul($dailyFromWeekly, (string)$remainingDays, 6);
        $weeklyMath = bcadd($weeklyPart, $remainderPart, 6);

        // 30+ days: use monthly math
        if ($days >= 30) {
            $fullMonths = intdiv($days, 30);
            $remainingMonthDays = $days % 30;
            // monthly_math = (full_months × monthly) + (remaining × (monthly / 30))
            $monthlyPart = bcmul($monthly, (string)$fullMonths, 6);
            $dailyFromMonthly = bcdiv($monthly, '30', 6);
            $monthRemainderPart = bcmul($dailyFromMonthly, (string)$remainingMonthDays, 6);
            $monthlyMath = bcadd($monthlyPart, $monthRemainderPart, 6);
            $rounded = bcround($monthlyMath, 2);

            $explanation = [];
            if ($fullMonths > 0) {
                $explanation[] = "{$fullMonths} full month(s) × \${$monthly}";
            }
            if ($remainingMonthDays > 0) {
                $dailyFromMonthlyRounded = bcround($dailyFromMonthly, 4);
                $explanation[] = "{$remainingMonthDays} remaining days × \${$dailyFromMonthlyRounded}/day (monthly/30)";
            }
            $explanation[] = "Total: \${$rounded} (monthly method)";

            return [
                'amount'      => $rounded,
                'method'      => 'monthly',
                'explanation' => $explanation,
            ];
        }

        // 8–29 days: weekly math, capped at monthly rate
        if (bccomp($weeklyMath, $monthly, 6) > 0) {
            // Weekly math exceeds monthly — cap at monthly rate
            $rounded = bcround($monthly, 2);
            return [
                'amount'      => $rounded,
                'method'      => 'weekly_capped',
                'explanation' => [
                    "{$days} days: weekly math = \$" . bcround($weeklyMath, 2) . " exceeds monthly rate \${$monthly}",
                    "Capped at monthly rate: \${$rounded}",
                ],
            ];
        }

        // Weekly math is cheaper — use it
        $rounded = bcround($weeklyMath, 2);
        $explanation = [];
        if ($fullWeeks > 0) {
            $explanation[] = "{$fullWeeks} week(s) × \${$weekly}";
        }
        if ($remainingDays > 0) {
            $dailyFromWeeklyRounded = bcround($dailyFromWeekly, 4);
            $explanation[] = "{$remainingDays} remaining days × \${$dailyFromWeeklyRounded}/day (weekly/7)";
        }
        $explanation[] = "Total: \${$rounded} (weekly method)";

        return [
            'amount'      => $rounded,
            'method'      => 'weekly',
            'explanation' => $explanation,
        ];
    }
}
