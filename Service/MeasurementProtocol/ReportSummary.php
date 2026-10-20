<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Per-day overview for the admin report: orders placed vs. server-side purchase delivery.
 */
class ReportSummary
{
    private const STATUSES = [
        QueueStorageInterface::STATUS_SENT,
        QueueStorageInterface::STATUS_PENDING,
        QueueStorageInterface::STATUS_PROCESSING,
        QueueStorageInterface::STATUS_FAILED,
        QueueStorageInterface::STATUS_EXPIRED,
        QueueStorageInterface::STATUS_SKIPPED,
    ];

    /**
     * @param array<string, int> $ordersByDay      'Y-m-d' => number of orders placed
     * @param array<int, array{day: string, event_type: string, status: string, total: int}> $eventRows
     * @return array<string, array> newest day first; each row: orders, untracked, per-status purchase counts,
     *                              refund/cancel sent counts, sent_rate (sent / orders, %, null when no orders)
     */
    public function build(array $ordersByDay, array $eventRows): array
    {
        $days = [];
        foreach ($ordersByDay as $day => $count) {
            $days[$day] = $this->emptyRow((int) $count);
        }
        foreach ($eventRows as $row) {
            $day = (string) $row['day'];
            $days[$day] ??= $this->emptyRow(0);
            $total = (int) $row['total'];
            if ($row['event_type'] === QueueStorageInterface::EVENT_PURCHASE) {
                if (isset($days[$day]['purchase'][$row['status']])) {
                    $days[$day]['purchase'][$row['status']] += $total;
                }
            } elseif ($row['status'] === QueueStorageInterface::STATUS_SENT) {
                $key = $row['event_type'] . '_sent';
                $days[$day][$key] = ($days[$day][$key] ?? 0) + $total;
            }
        }
        foreach ($days as &$row) {
            $tracked = array_sum($row['purchase']);
            $row['untracked'] = max(0, $row['orders'] - $tracked);
            $row['sent_rate'] = $row['orders'] > 0
                ? round(100 * $row['purchase'][QueueStorageInterface::STATUS_SENT] / $row['orders'], 1)
                : null;
        }
        unset($row);
        krsort($days);

        return $days;
    }

    private function emptyRow(int $orders): array
    {
        return [
            'orders' => $orders,
            'purchase' => array_fill_keys(self::STATUSES, 0),
            QueueStorageInterface::EVENT_REFUND . '_sent' => 0,
            QueueStorageInterface::EVENT_CANCEL . '_sent' => 0,
        ];
    }
}
