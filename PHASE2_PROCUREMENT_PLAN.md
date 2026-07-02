# Phase 2 — Real Purchase Order Workflow (Laravel + Filament) — Build Plan

**Matlamat Phase 2:** naik taraf daripada "1 baris = 1 cadangan order" (`procurement_orders` sekarang)
kepada **Purchase Order sebenar** — satu PO ada banyak item, kelulusan, jangkaan tarikh terima, dan
rekod penerimaan (GRN) yang kemas kini status. Ini asas untuk budget kawalan (Open-to-Buy) dan
supplier scorecard.

## Skop penuh (5 keping, ikut kebergantungan)
```
2A. Model PO sebenar (header + line item)         <- ASAS, mesti dulu
2B. Goods Receipt Note (GRN) - terima barang       <- perlukan 2A
2C. Stock Transfer workflow (naik taraf Rearrange) <- BEBAS, boleh selari
2D. Open-to-Buy budget (had perbelanjaan bulanan)  <- perlukan 2A (kira spend)
2E. Supplier Scorecard (lead time, fill rate, dll) <- perlukan 2A+2B (data sejarah)
```

---

## 2A. Model Purchase Order

### Skema baru (jadual Laravel sendiri - connection `sqlite`/database.sqlite, BUKAN jemisys.db,
sebab ini data operasi kita punya, bukan data JEMiSys mentah)

```
purchase_orders
  id, po_number (unik, auto cth PO-2026-0001), vendor_code, vendor_name,
  status (Draft -> Pending Approval -> Approved -> Sent -> Partially Received -> Received -> Cancelled),
  expected_delivery_date, notes,
  created_by, approved_by, approved_at,
  created_at, updated_at

purchase_order_lines
  id, purchase_order_id (FK), internal_code, item_desc, category_code,
  qty_ordered, unit_cost, qty_received (default 0),
  source_recommendation_id (nullable FK ke procurement_orders lama - jejak asal cadangan),
  created_at, updated_at
```

### Alur status
```
Draft --(submit)--> Pending Approval --(manager approve)--> Approved --(hantar ke supplier)--> Sent
Sent --(GRN, sebahagian)--> Partially Received --(GRN, semua item cukup)--> Received
Mana-mana peringkat sebelum Sent --(batal)--> Cancelled
```
Role-gating (sepadan Flask): staff cipta Draft & submit; **manager sahaja** boleh Approve.

### Filament
- `PurchaseOrderResource` — form header (vendor, tarikh jangka, nota) + repeater/relation-manager
  untuk line items (boleh tambah terus dari senarai Order Recommendation sedia ada — checkbox pilih
  → auto isi qty_ordered = recommend_qty, unit_cost dari rujukan kos design)
- Table: filter status/vendor/tarikh, print/export PO sebagai PDF (untuk hantar ke supplier)
- Actions: Submit for Approval, Approve (manager), Mark as Sent, Cancel

---

## 2B. Goods Receipt Note (GRN)

### Skema
```
goods_receipts
  id, grn_number, purchase_order_id (FK), received_by, received_at, notes

goods_receipt_lines
  id, goods_receipt_id (FK), purchase_order_line_id (FK), qty_received, condition_notes
```

### Logik
- Buka PO status `Sent`/`Partially Received` → borang "Terima Barang": senarai line item,
  masukkan qty diterima setiap satu (boleh kurang daripada qty_ordered - partial delivery biasa).
- Selepas simpan: `purchase_order_lines.qty_received` bertambah; PO status auto-kira:
  semua line `qty_received >= qty_ordered` → `Received`; sebahagian → `Partially Received`.
- **TIDAK** tulis balik ke `jemisys.db` (itu sistem POS sebenar, di luar kawalan kita) — GRN ni
  rekod dalaman sahaja untuk jejak "apa yang patut ada" vs "apa yang JEMiSys kata ada" (semakan silang).

### Filament
- `GoodsReceiptResource` atau relation-manager di dalam `PurchaseOrderResource`
- Papar "outstanding qty" (ordered - received) supaya staff tahu apa lagi belum sampai

---

## 2C. Stock Transfer Workflow (naik taraf Rearrange sedia ada)

Rearrange (Phase 1) sekadar **cadangan** (baca-sahaja). Phase 2: jadikan **boleh jejak**.

### Skema
```
stock_transfers
  id, internal_code, from_store, to_store, qty, status (Requested->In Transit->Received),
  requested_by, requested_at, received_by, received_at
```

### Filament
- Butang "Cipta Transfer" terus dari page Rearrange sedia ada (guna cadangan yang dah dikira)
- `StockTransferResource`: senarai transfer, advance status (sepadan pattern advance_order Flask)

---

## 2D. Open-to-Buy Budget

### Skema
```
budget_periods
  id, period_label (cth "2026-08"), category_code (nullable = keseluruhan), budget_amount,
  created_by
```
Spend dikira **live** daripada `purchase_orders` (SUM `qty_ordered * unit_cost` utk PO status
bukan Cancelled, dalam period berkenaan) — tak perlu jadual "spend" berasingan.

### Filament
- Widget: bar budget vs spent per kategori/bulan (macam mockup awal — "RM 1.2j / 2.0j")
- Amaran bila PO baru akan lampaui budget

---

## 2E. Supplier Scorecard

Semua **dikira**, tiada jadual baru:
- **Lead time**: rata-rata (received_at - approved_at) per vendor, daripada purchase_orders+GRN
- **Fill rate**: SUM(qty_received)/SUM(qty_ordered) per vendor
- **Sell-through barang vendor**: sambung ke `procurement_report.py`/`analytics` punya velocity data
- Widget/Resource baru `SupplierScorecardResource` (read-only, agregat)

---

## Susunan pelaksanaan dicadangkan
1. **2A (Model PO)** — wajib dulu, asas semua
2. **2B (GRN)** — sambung terus lepas 2A, tanpa ni PO takkan pernah "selesai"
3. **2C (Transfer)** — boleh buat BILA-BILA (bebas), quick win sebab logik dah teruji (Rearrange)
4. **2D (Budget)** — lepas 2A stabil
5. **2E (Scorecard)** — paling akhir, perlukan data sejarah 2A+2B terkumpul dulu untuk bermakna

## Ujian (ikut pattern Phase 1)
Setiap sub-fasa dapat Pest/PHPUnit test sendiri (role-gating approve, status transition GRN,
budget overspend warning) — sama macam `InventoryPieceResourceTest`/`DashboardWidgetsTest` sedia ada.

## Nota migrasi Postgres/VM (tak berubah)
Jadual 2A-2E semua di connection **default** (`sqlite`/database.sqlite), BUKAN `jemisys` — bila
migrate ke VM, flow sama macam data users/permissions sekarang: tukar `DB_CONNECTION` default,
jadual-jadual ni ikut sekali (bukan snapshot JEMiSys yang perlu load_data.py berasingan).
