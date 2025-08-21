<?php
declare(strict_types=1);

/**
 * Keep legacy inventory_items.stock in sync with the sum of stock_levels per item.
 */
function sync_item_total(PDO $pdo, int $itemId): void
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM stock_levels WHERE item_id = ?"
    );
    $stmt->execute([$itemId]);
    $total = (int) $stmt->fetchColumn();
    $upd = $pdo->prepare("UPDATE inventory_items SET stock = ? WHERE id = ?");
    $upd->execute([$total, $itemId]);
}

/**
 * Ensure a (item_id, location_id) row exists so updates are atomic.
 */
function ensure_level_row(PDO $pdo, int $itemId, int $locId): void
{
    $pdo->prepare(
        "
    INSERT INTO stock_levels (item_id, location_id, qty)
    VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE qty = qty
  "
    )->execute([$itemId, $locId]);
}

/**
 * Read current qty for an item at a location (0 if none).
 */
function get_level(PDO $pdo, int $itemId, int $locId): int
{
    $stmt = $pdo->prepare(
        "SELECT qty FROM stock_levels WHERE item_id = ? AND location_id = ?"
    );
    $stmt->execute([$itemId, $locId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int) $row["qty"] : 0;
}

/**
 * Append an audit log row.
 */
function log_tx(
    PDO $pdo,
    int $itemId,
    ?int $from,
    ?int $to,
    int $qty,
    string $action,
    ?string $note,
    ?int $userId
): void {
    $stmt = $pdo->prepare("
    INSERT INTO stock_transactions (item_id, from_location_id, to_location_id, qty, action, note, user_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
    $stmt->execute([$itemId, $from, $to, $qty, $action, $note, $userId]);
}

/**
 * STOCK IN
 */
function stock_in(
    PDO $pdo,
    int $itemId,
    int $locId,
    int $qty,
    ?string $note,
    ?int $userId
): void {
    if ($qty <= 0) {
        throw new InvalidArgumentException("Quantity must be > 0");
    }
    $pdo->beginTransaction();
    try {
        ensure_level_row($pdo, $itemId, $locId);

        // Lock then update
        $pdo->prepare(
            "SELECT qty FROM stock_levels WHERE item_id = ? AND location_id = ? FOR UPDATE"
        )->execute([$itemId, $locId]);
        $pdo->prepare(
            "UPDATE stock_levels SET qty = qty + ? WHERE item_id = ? AND location_id = ?"
        )->execute([$qty, $itemId, $locId]);

        log_tx($pdo, $itemId, null, $locId, $qty, "IN", $note, $userId);

        // <<< keep inventory_items.stock in sync
        sync_item_total($pdo, $itemId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * STOCK OUT
 */
function stock_out(
    PDO $pdo,
    int $itemId,
    int $locId,
    int $qty,
    ?string $note,
    ?int $userId
): void {
    if ($qty <= 0) {
        throw new InvalidArgumentException("Quantity must be > 0");
    }
    $pdo->beginTransaction();
    try {
        ensure_level_row($pdo, $itemId, $locId);

        // Lock then validate & update
        $stmt = $pdo->prepare(
            "SELECT qty FROM stock_levels WHERE item_id = ? AND location_id = ? FOR UPDATE"
        );
        $stmt->execute([$itemId, $locId]);
        $cur = (int) ($stmt->fetchColumn() ?? 0);
        if ($cur < $qty) {
            throw new RuntimeException("Not enough stock in From location");
        }

        $pdo->prepare(
            "UPDATE stock_levels SET qty = qty - ? WHERE item_id = ? AND location_id = ?"
        )->execute([$qty, $itemId, $locId]);

        log_tx($pdo, $itemId, $locId, null, $qty, "OUT", $note, $userId);

        // <<< keep inventory_items.stock in sync
        sync_item_total($pdo, $itemId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * TRANSFER
 */
function stock_transfer(
    PDO $pdo,
    int $itemId,
    int $fromLoc,
    int $toLoc,
    int $qty,
    ?string $note,
    ?int $userId
): void {
    if ($qty <= 0) {
        throw new InvalidArgumentException("Quantity must be > 0");
    }
    if ($fromLoc === $toLoc) {
        throw new InvalidArgumentException("From and To must be different");
    }
    $pdo->beginTransaction();
    try {
        ensure_level_row($pdo, $itemId, $fromLoc);
        ensure_level_row($pdo, $itemId, $toLoc);

        // Lock FROM row, validate & update
        $stmt = $pdo->prepare(
            "SELECT qty FROM stock_levels WHERE item_id = ? AND location_id = ? FOR UPDATE"
        );
        $stmt->execute([$itemId, $fromLoc]);
        $cur = (int) ($stmt->fetchColumn() ?? 0);
        if ($cur < $qty) {
            throw new RuntimeException("Not enough stock in From location");
        }

        $pdo->prepare(
            "UPDATE stock_levels SET qty = qty - ? WHERE item_id = ? AND location_id = ?"
        )->execute([$qty, $itemId, $fromLoc]);

        // Update TO row
        $pdo->prepare(
            "UPDATE stock_levels SET qty = qty + ? WHERE item_id = ? AND location_id = ?"
        )->execute([$qty, $itemId, $toLoc]);

        log_tx(
            $pdo,
            $itemId,
            $fromLoc,
            $toLoc,
            $qty,
            "TRANSFER",
            $note,
            $userId
        );

        // <<< keep inventory_items.stock in sync
        sync_item_total($pdo, $itemId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
