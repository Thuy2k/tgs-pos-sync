# Conflict Resolution - Foreign Key Update Logic

## Vấn đề

Khi POS offline tạo đơn hàng với `local_ledger_id = 56`, nhưng Hub (online) đã có đơn với cùng ID = 56.

## Giải pháp: Di chuyển + Update Foreign Keys

### Flow chi tiết:

```
TRƯỚC CONFLICT:
POS Database:
  local_ledger (ID=56): Đơn offline tại shop
  local_ledger_item (5 items): local_ledger_id = 56
  local_ledger_meta (ID=101): linked to ledger 56
  outbox events: data = {local_ledger_id: 56}

HUB Database:
  local_ledger (ID=56): Đơn online trên website
```

### Khi Pull về POS:

**Step 1: Detect Conflict**
```sql
SELECT COUNT(*) FROM local_ledger WHERE local_ledger_id = 56
-- Result: 1 (đã tồn tại)
```

**Step 2: Đọc local record**
```sql
SELECT * FROM local_ledger WHERE local_ledger_id = 56
-- Lưu toàn bộ data của đơn offline
```

**Step 3: Xóa tạm local record**
```sql
DELETE FROM local_ledger WHERE local_ledger_id = 56
```

**Step 4: Insert lại local record (auto-increment → ID mới)**
```sql
INSERT INTO local_ledger (...) VALUES (...)
-- Không set local_ledger_id → MySQL tự tạo ID = 127
```

**Step 5: Update Foreign Keys trong bảng con**
```sql
-- Update items
UPDATE local_ledger_item 
SET local_ledger_id = 127 
WHERE local_ledger_id = 56
-- 5 rows affected

-- Update meta reference (nếu có cột link ngược)
-- Hoặc meta được link qua local_ledger.local_ledger_meta_id
```

**Step 6: Update Outbox Events**
```sql
-- Lấy pending events của ledger này
SELECT id, data FROM tgs_sync_outbox 
WHERE table_name = 'wp_local_ledger' 
  AND status = 'pending'

-- Parse JSON và update ID
data = {"local_ledger_id": 56, ...}
→ {"local_ledger_id": 127, ...}
```

**Step 7: Insert Hub record vào ID cũ**
```sql
INSERT INTO local_ledger (local_ledger_id, ...) 
VALUES (56, ...)
-- Đặt đúng ID từ Hub
```

### SAU CONFLICT RESOLUTION:

```
POS Database:
  local_ledger (ID=56): Đơn ONLINE từ Hub (ưu tiên)
  local_ledger (ID=127): Đơn OFFLINE tại shop (di chuyển)
  
  local_ledger_item (5 items): local_ledger_id = 127 (đã update)
  local_ledger_meta: linked to ledger 127
  
  outbox events: data = {local_ledger_id: 127} (đã update)
```

## Code Implementation

### Foreign Key Relationships Map

```php
$relationships = array(
    'wp_local_ledger' => array(
        array('table' => 'local_ledger_item', 'fk' => 'local_ledger_id'),
        array('table' => 'local_ledger_meta', 'fk' => 'local_ledger_id'), // Nếu có
    ),
);
```

### Update Logic

```php
private static function update_foreign_keys($table_name, $old_id, $new_id) {
    global $wpdb;
    
    foreach ($relationships[$table_name] as $relation) {
        $child_table = $wpdb->prefix . $relation['table'];
        $fk_column = $relation['fk'];
        
        $wpdb->update(
            $child_table,
            array($fk_column => $new_id),
            array($fk_column => $old_id)
        );
    }
}
```

## Lưu ý quan trọng

### 1. **KHÔNG dùng ID Mapping Table**
- Không cần lưu `local_id ↔ hub_id`
- Chỉ di chuyển data với foreign key updates
- Đơn giản hơn, ít bug hơn

### 2. **Bảng Meta**
- `local_ledger` có cột `local_ledger_meta_id` (link 1-1)
- `local_ledger_meta` KHÔNG có cột link ngược
- Khi di chuyển ledger, meta_id đi theo (không cần update)

### 3. **Parent-Child Relationships**
- `local_ledger` (cha) → `local_ledger_item` (con)
- Khi cha di chuyển từ ID=56 → ID=127
- Tất cả con phải update `local_ledger_id` từ 56 → 127

### 4. **Transaction Safety**
Không dùng MySQL transaction vì:
- WordPress `$wpdb` không hỗ trợ nested transactions tốt
- Nếu fail ở giữa → data inconsistent
- **TODO:** Cân nhắc wrap trong `START TRANSACTION ... COMMIT`

## Testing Checklist

- [x] Tạo order tại POS → ID=56
- [x] Giả lập Hub record với ID=56
- [ ] Pull về → Trigger conflict resolution
- [ ] Verify: Local record di chuyển sang ID=127
- [ ] Verify: 5 items update `local_ledger_id` từ 56 → 127
- [ ] Verify: Meta vẫn link đúng
- [ ] Verify: Outbox events update JSON data
- [ ] Verify: Hub record nằm ở ID=56
- [ ] Push lên Hub → Success với ID=127

## Logs

**Success log:**
```
[TGS POS Pull] Conflict detected: wp_local_ledger ID=56 - Reassigning local record to new ID
[TGS POS Pull] Updated 5 rows in local_ledger_item: local_ledger_id from 56 to 127
[TGS POS Pull] Conflict resolved: wp_local_ledger Local record moved from ID=56 to ID=127, Hub record inserted at ID=56
[TGS POS Sync] Pull before push: pulled=1, conflicts_resolved=1
```

## Edge Cases

### Case 1: Items không có (đơn hàng rỗng)
- Update foreign key return 0 rows → OK, không lỗi

### Case 2: Multiple conflicts (ID=56, 57, 58)
- Resolve tuần tự từng ID
- ID mới: 127, 128, 129

### Case 3: Outbox events đã push (status=sent)
- Không update events đã sent
- Chỉ update `status='pending'`

### Case 4: Meta đã bị xóa (deleted_at NOT NULL)
- Vẫn di chuyển, không quan tâm deleted flag
- Sau khi push, Hub tự xử lý soft delete

## Performance

**Với 1 conflict:**
- 1 SELECT (check exists)
- 1 SELECT (read local)
- 1 DELETE (remove old)
- 1 INSERT (create new)
- N UPDATE (items, meta)
- M UPDATE (outbox events)
- 1 INSERT (hub record)

**Total:** ~7-10 queries/conflict

**Acceptable** vì conflict hiếm khi xảy ra (chỉ khi POS offline lâu).

## Date
2026-07-02
