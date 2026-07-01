# CHANGELOG - TGS POS Sync Plugin

## [1.0.0] - 2026-07-02

### Added - Tính năng mới
- **Auto Migration System**: Tự động check và update database schema khi plugin load
  - Không cần deactivate/activate thủ công
  - Tự động phát hiện và thêm các cột thiếu
  
- **Transaction Support for Events**: Thêm 2 cột mới vào bảng `wp_tgs_sync_outbox`
  - `transaction_id` (varchar 64): Group các events của cùng 1 đơn hàng
    - Ví dụ: `txn_order_123` sẽ group 3 events (ledger, items, meta)
    - Đảm bảo push atomic: all-or-nothing
  - `parent_event_id` (varchar 64): Parent event cho child events
    - Event đầu tiên là parent (ledger)
    - Các event sau là children (items, meta)
    - Dễ trace và debug khi có lỗi

### Changed - Thay đổi
- **Database Schema**: Cập nhật schema với comment đầy đủ cho mọi cột
  - Đánh dấu `[NEW v1.0.0]` cho các cột mới
  - Mô tả rõ ràng mục đích và ví dụ sử dụng

### Technical Details
- Hook: `plugins_loaded` → `check_database_schema()`
- Version tracking: Option `tgs_pos_sync_db_version`
- Migration method: `TGS_POS_Database::check_and_add_missing_columns()`

### Upgrade Notes
- **Từ bản cũ (không có transaction_id)**: 
  - Chỉ cần deactivate → activate plugin
  - Hoặc reload trang admin
  - Schema tự động update, không mất dữ liệu

### Example - Cách hoạt động
Khi tạo đơn hàng mới:
```
Transaction: txn_order_56
├─ Event 1 (Parent): order_created → wp_local_ledger
├─ Event 2 (Child):  order_item_created → wp_local_ledger_item
└─ Event 3 (Child):  order_meta_created → wp_local_ledger_meta

Status: pending → sent → acked
```

---

## [0.9.0] - Before 2026-07-02
### Initial Release
- Basic sync outbox table
- Manual migration required
