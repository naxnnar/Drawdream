<?php
// สร้างแถว donation + payment_transaction สถานะ pending หลังได้ Omise charge (บริจาคเด็กรายบุคคล)

declare(strict_types=1);

/**
 * @return int category_id สำหรับบริจาคเด็กรายบุคคล
 */
function drawdream_get_or_create_child_donate_category_id(mysqli $conn): int
{
    $stmt = $conn->prepare('SELECT category_id FROM donate_category WHERE child_donate IS NOT NULL LIMIT 1');
    if ($stmt) {
        $stmt->execute();
        $cat = $stmt->get_result()->fetch_assoc();
        if ($cat) {
            return (int)$cat['category_id'];
        }
    }
    $col = @$conn->query("SHOW COLUMNS FROM donate_category LIKE 'child_donate'");
    if ($col && $col->num_rows === 0) {
        @$conn->query('ALTER TABLE donate_category ADD COLUMN child_donate VARCHAR(100) NULL');
    }
    @$conn->query("INSERT INTO donate_category (child_donate) VALUES ('เด็กรายบุคคล')");
    return (int)$conn->insert_id;
}

/**
 * บันทึก donation (pending) + payment_transaction (pending) หลังสร้าง charge
 *
 * @return int donate_id หรือ 0 ถ้าล้มเหลว
 */
function drawdream_insert_pending_child_donation(
    mysqli $conn,
    int $childId,
    int $donorUserId,
    float $amountBaht,
    string $omiseChargeId
): int {
    if ($childId <= 0 || $donorUserId <= 0 || $amountBaht < 20 || $omiseChargeId === '') {
        return 0;
    }

    $categoryId = drawdream_get_or_create_child_donate_category_id($conn);

    $taxId = '';
    $stTax = $conn->prepare('SELECT tax_id FROM donor WHERE user_id = ? LIMIT 1');
    if ($stTax) {
        $stTax->bind_param('i', $donorUserId);
        $stTax->execute();
        $rowT = $stTax->get_result()->fetch_assoc();
        if ($rowT) {
            $taxId = (string)($rowT['tax_id'] ?? '');
        }
    }

    $serviceFee = 0.0;
    $pending = 'pending';
    $transferTs = date('Y-m-d H:i:s');

    if (!$conn->begin_transaction()) {
        return 0;
    }
    try {
        $insD = $conn->prepare(
            'INSERT INTO donation (category_id, target_id, donor_id, amount, service_fee, payment_status, transfer_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insD) {
            throw new RuntimeException('prepare donation failed');
        }
        $insD->bind_param(
            'iiiddss',
            $categoryId,
            $childId,
            $donorUserId,
            $amountBaht,
            $serviceFee,
            $pending,
            $transferTs
        );
        if (!$insD->execute()) {
            throw new RuntimeException('insert donation failed');
        }
        $donateId = (int)$conn->insert_id;
        if ($donateId <= 0) {
            throw new RuntimeException('no donate_id');
        }

        $insP = $conn->prepare(
            'INSERT INTO payment_transaction (donate_id, tax_id, omise_charge_id, transaction_status) VALUES (?, ?, ?, ?)'
        );
        if (!$insP) {
            throw new RuntimeException('prepare payment_transaction failed');
        }
        $ptPending = 'pending';
        $insP->bind_param('isss', $donateId, $taxId, $omiseChargeId, $ptPending);
        if (!$insP->execute()) {
            throw new RuntimeException('insert payment_transaction failed');
        }

        $conn->commit();
        return $donateId;
    } catch (Throwable $e) {
        $conn->rollback();
        return 0;
    }
}

/**
 * อัปเดตแถว pending → สำเร็จ + บันทึก child_donations
 */
function drawdream_finalize_child_donation(
    mysqli $conn,
    int $childId,
    int $donateId,
    string $chargeId,
    float $amountBaht,
    int $donorUserId
): bool {
    if ($childId <= 0 || $donateId <= 0 || $chargeId === '' || $donorUserId <= 0) {
        return false;
    }

    $chk = $conn->prepare(
        'SELECT donor_id, target_id, payment_status FROM donation WHERE donate_id = ? LIMIT 1'
    );
    if (!$chk) {
        return false;
    }
    $chk->bind_param('i', $donateId);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row
        || (int)($row['donor_id'] ?? 0) !== $donorUserId
        || (int)($row['target_id'] ?? 0) !== $childId
        || (string)($row['payment_status'] ?? '') !== 'pending'
    ) {
        return false;
    }

    $serviceFee = 0.0;
    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        $upd = $conn->prepare(
            'UPDATE donation
             SET amount = ?, service_fee = ?, payment_status = \'completed\', transfer_datetime = NOW()
             WHERE donate_id = ? AND payment_status = \'pending\''
        );
        if (!$upd) {
            throw new RuntimeException('prepare update donation');
        }
        $upd->bind_param('ddi', $amountBaht, $serviceFee, $donateId);
        $upd->execute();
        if ($upd->affected_rows < 1) {
            throw new RuntimeException('update donation');
        }

        $upt = $conn->prepare(
            'UPDATE payment_transaction SET transaction_status = \'completed\'
             WHERE omise_charge_id = ? AND transaction_status = \'pending\''
        );
        if (!$upt) {
            throw new RuntimeException('prepare pt');
        }
        $upt->bind_param('s', $chargeId);
        $upt->execute();

        $conn->query(
            "CREATE TABLE IF NOT EXISTS child_donations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT NOT NULL,
                donor_user_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                donated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(child_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $insC = $conn->prepare(
            'INSERT INTO child_donations (child_id, donor_user_id, amount) VALUES (?, ?, ?)'
        );
        if (!$insC) {
            throw new RuntimeException('prepare child_donations');
        }
        $insC->bind_param('iid', $childId, $donorUserId, $amountBaht);
        $insC->execute();

        if (function_exists('drawdream_child_sync_sponsorship_status')) {
            drawdream_child_sync_sponsorship_status($conn, $childId);
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}
