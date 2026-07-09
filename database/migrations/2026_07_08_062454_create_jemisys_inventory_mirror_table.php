<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jemisys_inventory_mirror', function (Blueprint $table) {
            $table->id();

            $table->string('StoreCode', 20);
            $table->string('InventoryCode', 20);
            $table->string('Description', 100)->nullable();
            $table->string('ClassCode', 20);
            $table->string('CategoryCode', 20);
            $table->string('CatalogueCode', 20);
            $table->string('DesignName', 50)->nullable();
            $table->string('InternalCode', 20)->nullable();
            $table->smallInteger('DesignVersion');
            $table->string('Range', 20)->nullable();
            $table->string('RangeType', 1)->nullable();
            $table->string('Setting', 20)->nullable();
            $table->string('SubSetting', 30);
            $table->decimal('GoldWeight', 18, 9);
            $table->decimal('GrossWeight', 18, 9);
            $table->decimal('Purity', 10, 4);
            $table->string('JewelSize', 5);
            $table->string('JewelLength', 5);
            $table->decimal('GoldRate', 10, 4);
            $table->decimal('GoldCost', 19, 4);
            $table->string('McCostType', 3)->nullable();
            $table->decimal('McCost', 10, 4);
            $table->decimal('McCostTotal', 10, 4);
            $table->string('McPriceType', 3)->nullable();
            $table->decimal('McPrice', 10, 4);
            $table->decimal('McPriceTotal', 10, 4);
            $table->string('PTaxCode', 20)->nullable();
            $table->decimal('TaxPercentage', 18, 2)->nullable();
            $table->decimal('SCPercentage', 18, 0)->nullable();
            $table->decimal('Tax1', 10, 4)->nullable();
            $table->decimal('Tax2', 10, 4)->nullable();
            $table->decimal('Tax3', 10, 4)->nullable();
            $table->string('STaxCode', 20)->nullable();
            $table->decimal('TotalCost', 19, 4);
            $table->decimal('AddCost', 10, 4);
            $table->decimal('AddCost2', 10, 4);
            $table->decimal('AddCost3', 10, 4);
            $table->decimal('AddCost4', 10, 4);
            $table->decimal('PurchaseQty', 18, 0);
            $table->decimal('QtyOnHand', 18, 0);
            $table->string('SuppCurrCode', 10)->nullable();
            $table->decimal('SuppTagPrice', 19, 4);
            $table->decimal('SuppDiscount', 19, 4);
            $table->decimal('SuppPrice', 19, 4);
            $table->string('ReportCurrCode', 10)->nullable();
            $table->decimal('ReportExchRate', 14, 6);
            $table->decimal('ReportTagPrice', 19, 4);
            $table->string('LocalCurrCode', 10)->nullable();
            $table->decimal('LocalExchRate', 14, 6);
            $table->decimal('MarkupPercentage', 8, 4)->nullable();
            $table->decimal('MarkupPercentage2', 18, 4)->nullable();
            $table->decimal('MarkupPercentage3', 18, 4);
            $table->string('CurrCode', 10);
            $table->decimal('ExchRate', 14, 6);
            $table->string('DiamondDesc', 200)->nullable();
            $table->decimal('DiamondCost', 19, 4);
            $table->decimal('DiamondPrice', 19, 4);
            $table->decimal('ProfitMargin', 19, 4);
            $table->decimal('TagPrice', 19, 4)->nullable();
            $table->string('DiamondCost2CurrCode', 10);
            $table->decimal('DiamondCost2', 19, 4);
            $table->decimal('DiamondPrice2', 19, 4);
            $table->string('TagPrice2CurrCode', 10);
            $table->decimal('TagPrice2', 19, 4);
            $table->string('Remarks', 100)->nullable();
            $table->string('LabelLine1', 20)->nullable();
            $table->string('LabelLine2', 20)->nullable();
            $table->string('LabelLine3', 20)->nullable();
            $table->string('LabelLine4', 20)->nullable();
            $table->string('LabelLine5', 20)->nullable();
            $table->string('SalesBillNo', 15);
            $table->dateTime('SalesDate')->nullable();
            $table->decimal('SalesAmount', 19, 4)->nullable();
            $table->string('SalesBy', 20)->nullable();
            $table->string('LocationCode', 20)->nullable();
            $table->string('Status', 1);
            $table->string('MiscRemarks', 50)->nullable();
            $table->string('RefNo', 20)->nullable();
            $table->decimal('CurrCost', 19, 4)->nullable();
            $table->boolean('Reserve')->nullable();
            $table->boolean('Transit')->nullable();
            $table->boolean('Consign');
            $table->boolean('Loan');
            $table->string('LoanToStore', 20)->nullable();
            $table->dateTime('LoanDate')->nullable();
            $table->string('TagType', 1);
            $table->string('SalesType', 1);
            $table->string('JobSheetNo', 10);
            $table->string('VendorCode', 20);
            $table->dateTime('PurchDate');
            $table->string('PurchInvoiceType', 1);
            $table->string('Brand', 20)->nullable();
            $table->boolean('Booked');
            $table->string('BookedBy', 20)->nullable();
            $table->string('BookedStore', 20)->nullable();
            $table->dateTime('BookedDate')->nullable();
            $table->string('DiscountCode', 10)->nullable();
            $table->tinyInteger('DiscountPercentage');
            $table->boolean('Discontinued');
            $table->boolean('Gift');
            $table->string('CreatedBy', 20)->nullable();
            $table->dateTime('CreatedDate');
            $table->string('ModifiedBy', 20)->nullable();
            $table->dateTime('ModifiedDate');
            $table->string('Polling', 1)->nullable();
            $table->string('TempRef1', 20)->nullable();
            $table->string('TempRef2', 20)->nullable();
            $table->string('TempRef3', 20)->nullable();
            $table->string('TransferToStore', 20)->nullable();
            $table->string('CustomerCode', 20)->nullable();
            $table->string('ConsignNo', 20)->nullable();
            $table->string('ConsignFromStore', 20)->nullable();
            $table->boolean('GMS');
            $table->dateTime('ReceivedDate');
            $table->decimal('OriginalCost', 19, 4);
            $table->string('InvoiceNo', 20)->nullable();
            $table->string('BookedRefNo', 20)->nullable();
            $table->decimal('ParentCost', 19, 4);
            $table->decimal('PurchaseGoldWeight', 10, 3);
            $table->string('PONo', 10)->nullable();
            $table->tinyInteger('POSeqNo');
            $table->boolean('Exchange');
            $table->decimal('AdjWeight', 10, 3);
            $table->decimal('AdjCost', 19, 4);
            $table->string('ItemType', 1);
            $table->decimal('GL_Percentage', 6, 3);
            $table->decimal('GL_Weight', 10, 3);
            $table->decimal('GL_Cost', 19, 4);
            $table->decimal('ReservePrice', 19, 4);
            $table->string('PurchaseType', 1);
            $table->string('RMRefNo', 15);
            $table->string('ParentCurrCode', 10)->nullable();
            $table->decimal('ActualCost', 19, 4);
            $table->string('ImagePath', 200)->nullable();
            $table->smallInteger('ReserveQty');
            $table->integer('ExchangeQty');
            $table->decimal('McCostF', 19, 4);
            $table->decimal('McCostTotalF', 19, 4);
            $table->decimal('ReserveWeight', 18, 3);
            $table->decimal('ExchangeWeight', 18, 3);
            $table->decimal('TransitWeight', 18, 3);
            $table->decimal('StockOutWeight', 18, 3);
            $table->decimal('ReturnWeight', 18, 3);
            $table->smallInteger('ConsignQty');
            $table->decimal('ConsignWeight', 18, 3);
            $table->string('CrossRefNo', 200)->nullable();

            $table->timestamp('synced_at');

            $table->unique(['StoreCode', 'InventoryCode']);
            $table->index('InternalCode');
            $table->index('VendorCode');
            $table->index('CategoryCode');
            $table->index('QtyOnHand');
            $table->index('SalesDate');
            $table->index('PurchDate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jemisys_inventory_mirror');
    }
};
