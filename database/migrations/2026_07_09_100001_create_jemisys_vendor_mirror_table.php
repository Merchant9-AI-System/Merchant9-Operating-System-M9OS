<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jemisys_vendor_mirror', function (Blueprint $table) {
            $table->id();

            $table->string('VendorCode', 20);
            $table->string('VendorType', 20)->nullable();
            $table->string('Description', 100);
            $table->string('Address1', 50);
            $table->string('Address2', 50);
            $table->string('Address3', 50);
            $table->string('Address4', 50);
            $table->string('State', 50);
            $table->string('PinCode', 20);
            $table->string('Email', 50)->nullable();
            $table->string('URL', 50)->nullable();
            $table->string('CurrCode', 10)->nullable();
            $table->decimal('DiscountPer', 18, 2);
            $table->string('PaymentMode', 10)->nullable();
            $table->string('Remarks', 100)->nullable();
            $table->string('Contact', 50)->nullable();
            $table->string('Telephone', 30)->nullable();
            $table->string('Telephone2', 30)->nullable();
            $table->string('Fax', 30)->nullable();
            $table->string('HandPhone', 30)->nullable();
            $table->string('Terms', 4);
            $table->string('BankName', 50)->nullable();
            $table->string('BankAddress1', 50)->nullable();
            $table->string('BankAddress2', 50)->nullable();
            $table->string('BankAddress3', 50)->nullable();
            $table->string('BankAcct', 50)->nullable();
            $table->string('BankSwift', 50)->nullable();
            $table->string('BenfName', 50)->nullable();
            $table->string('BenfAddress1', 50)->nullable();
            $table->string('BenfAddress2', 50)->nullable();
            $table->string('BenfAddress3', 50)->nullable();
            $table->string('BenfTelephone', 30)->nullable();
            $table->string('BenfFax', 30)->nullable();
            $table->string('PayType', 1);
            $table->string('IsFGSupplier', 1);
            $table->string('IsDiamondSupplier', 1);
            $table->string('IsStoneSupplier', 1);
            $table->string('IsGoldbarSupplier', 1);
            $table->string('IsSubCon', 1);
            $table->string('IsManuf', 1);
            $table->string('IsSetting', 1);
            $table->string('IsPlatting', 1);
            $table->string('IsService', 1);
            $table->string('GlAcct1', 10);
            $table->string('GlAcct2', 10);
            $table->smallInteger('OrderOfDisplay');
            $table->string('CreatedBy', 20)->nullable();
            $table->dateTime('CreatedDate');
            $table->string('ModifiedBy', 20)->nullable();
            $table->dateTime('ModifiedDate');
            $table->string('Polling', 1)->nullable();
            $table->boolean('InterfaceToAP');
            $table->string('APVendorCode', 20)->nullable();
            $table->boolean('Active');
            $table->string('TaxCode', 20)->nullable();
            $table->boolean('IsGMSSupplier');
            $table->boolean('GoldFixing');

            $table->timestamp('synced_at');

            $table->unique('VendorCode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jemisys_vendor_mirror');
    }
};
