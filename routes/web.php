<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Modules\Compliance\Http\Controllers\ComplianceController;
use App\Modules\Costing\Http\Controllers\CostSheetController;
use App\Modules\Dispatch\Http\Controllers\DeliveryChallanController;
use App\Modules\Dispatch\Http\Controllers\PackingListController;
use App\Modules\Dispatch\Http\Controllers\TripController;
use App\Modules\Finance\Http\Controllers\CreditNoteController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\ReceiptController;
use App\Modules\Finance\Http\Controllers\SalesInvoiceController;
use App\Modules\Inventory\Http\Controllers\MaterialIssueController;
use App\Modules\Inventory\Http\Controllers\PhysicalCountController;
use App\Modules\Inventory\Http\Controllers\StockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\StockEnquiryController;
use App\Modules\Inventory\Http\Controllers\StockLotController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Manufacturing\Http\Controllers\FgReceiptController;
use App\Modules\Manufacturing\Http\Controllers\JobCardController;
use App\Modules\MasterData\Http\Controllers\CustomerController;
use App\Modules\MasterData\Http\Controllers\ItemController;
use App\Modules\MasterData\Http\Controllers\MachineController;
use App\Modules\MasterData\Http\Controllers\SupplierController;
use App\Modules\Planning\Http\Controllers\MrpController;
use App\Modules\Planning\Http\Controllers\PlanningBoardController;
use App\Modules\Procurement\Http\Controllers\GrnController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use App\Modules\Procurement\Http\Controllers\SupplierBillController;
use App\Modules\Procurement\Http\Controllers\SupplierRfqController;
use App\Modules\Product\Http\Controllers\ArtworkController;
use App\Modules\Product\Http\Controllers\ArtworkVersionController;
use App\Modules\Product\Http\Controllers\BomController;
use App\Modules\Product\Http\Controllers\ProductController;
use App\Modules\Product\Http\Controllers\ProductSpecController;
use App\Modules\Product\Http\Controllers\RoutingController;
use App\Modules\Product\Http\Controllers\ToolController;
use App\Modules\Quality\Http\Controllers\LabController;
use App\Modules\Quality\Http\Controllers\NcrController;
use App\Modules\Quality\Http\Controllers\QcInspectionController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Sales\Http\Controllers\InquiryController;
use App\Modules\Sales\Http\Controllers\PriceListController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Trade\Http\Controllers\ImportShipmentController;
use App\Modules\Trade\Http\Controllers\LetterOfCreditController;
use App\Support\Export\Http\Controllers\ExportController;
use App\Support\Http\Controllers\BulkTransitionController;
use App\Support\Http\LandingPage;
use App\Support\Import\Http\Controllers\ImportController;
use App\Support\Notifications\Http\Controllers\NotificationInboxController;
use App\Support\Platform\Http\Controllers\AuditLogController;
use App\Support\Platform\Http\Controllers\NumberSequenceController;
use App\Support\Platform\Http\Controllers\OrganisationController;
use App\Support\Platform\Http\Controllers\RoleController;
use App\Support\Platform\Http\Controllers\SearchController;
use App\Support\Platform\Http\Controllers\SettingController;
use App\Support\Platform\Http\Controllers\UserController;
use App\Support\Print\Http\Controllers\DocumentPrintController;
use App\Support\Reference\Http\Controllers\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
 * Every route carries permission middleware. A route with none fails RoutePermissionTest
 * (06-rbac §7) — the two exceptions are the login screen and the health check.
 */

Route::get('/', fn () => redirect(LandingPage::for(auth()->user())))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function (): void {
    // Own-account routes carry no permission: every authenticated user may change their own
    // password, and gating that behind a permission is how people keep sharing one.
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');

    // Own-user inbox: every authenticated user reads their own rows. A permission here
    // would either lock the bell or grant nothing the ownership query does not already.
    Route::get('notifications', [NotificationInboxController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationInboxController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationInboxController::class, 'readAll'])->name('notifications.read-all');

    Route::get('dashboard', DashboardController::class)
        ->middleware('can:report.dashboard')
        ->name('dashboard');

    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:report.view_any')
        ->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])
        ->middleware('can:report.view')
        ->name('reports.show');

    // ---- Master data ---------------------------------------------------------------
    Route::resource('items', ItemController::class)
        ->middlewareFor(['index', 'show'], 'can:item.view_any')
        ->middlewareFor(['create', 'store'], 'can:item.create')
        ->middlewareFor(['edit', 'update'], 'can:item.update')
        ->middlewareFor('destroy', 'can:item.delete');

    Route::resource('customers', CustomerController::class)
        ->middlewareFor(['index', 'show'], 'can:customer.view_any')
        ->middlewareFor(['create', 'store'], 'can:customer.create')
        ->middlewareFor(['edit', 'update'], 'can:customer.update')
        ->middlewareFor('destroy', 'can:customer.delete');

    Route::resource('suppliers', SupplierController::class)
        ->middlewareFor(['index', 'show'], 'can:supplier.view_any')
        ->middlewareFor(['create', 'store'], 'can:supplier.create')
        ->middlewareFor(['edit', 'update'], 'can:supplier.update')
        ->middlewareFor('destroy', 'can:supplier.delete');

    Route::resource('machines', MachineController::class)
        ->middlewareFor(['index', 'show'], 'can:machine.view_any')
        ->middlewareFor(['create', 'store'], 'can:machine.create')
        ->middlewareFor(['edit', 'update'], 'can:machine.update')
        ->middlewareFor('destroy', 'can:machine.delete');

    // ---- Engineering ---------------------------------------------------------------
    Route::resource('products', ProductController::class)
        ->middlewareFor(['index', 'show'], 'can:product.view_any')
        ->middlewareFor(['create', 'store'], 'can:product.create')
        ->middlewareFor(['edit', 'update'], 'can:product.update')
        ->middlewareFor('destroy', 'can:product.delete');

    Route::post('products/{product}/specs', [ProductSpecController::class, 'store'])
        ->middleware('can:product_spec.create')->name('products.specs.store');
    Route::post('specs/{spec}/make-current', [ProductSpecController::class, 'makeCurrent'])
        ->middleware('can:product_spec.make_current')->name('specs.make-current');
    Route::post('specs/preview', [ProductSpecController::class, 'preview'])
        ->middleware('can:product_spec.view_any')->name('specs.preview');

    Route::get('artworks', [ArtworkController::class, 'index'])
        ->middleware('can:artwork.view_any')->name('artworks.index');
    Route::get('artworks/{artwork}', [ArtworkController::class, 'show'])
        ->middleware('can:artwork.view')->name('artworks.show');
    Route::post('artworks', [ArtworkController::class, 'store'])
        ->middleware('can:artwork.create')->name('artworks.store');
    Route::put('artworks/{artwork}', [ArtworkController::class, 'update'])
        ->middleware('can:artwork.update')->name('artworks.update');
    Route::post('artworks/{artwork}/versions', [ArtworkVersionController::class, 'store'])
        ->middleware('can:artwork.create')->name('artworks.versions.store');
    // Transition endpoints are gated on `.view`, not on one named action: a target's own
    // permission is checked by the state machine (StateMachine::assertPermitted). Gating the
    // route on `.submit` locked approvers out — the MD holds `artwork.approve` and nothing else.
    Route::post('artwork-versions/{version}/transition', [ArtworkVersionController::class, 'transition'])
        ->middleware('can:artwork.view')->name('artwork-versions.transition');

    Route::get('boms', [BomController::class, 'index'])
        ->middleware('can:bom.view_any')->name('boms.index');
    Route::post('products/{product}/boms', [BomController::class, 'store'])
        ->middleware('can:bom.create')->name('products.boms.store');
    Route::post('boms/{bom}/activate', [BomController::class, 'activate'])
        ->middleware('can:bom.activate')->name('boms.activate');

    Route::resource('routings', RoutingController::class)
        ->middlewareFor(['index', 'show'], 'can:routing.view_any')
        ->middlewareFor(['create', 'store'], 'can:routing.create')
        ->middlewareFor(['edit', 'update'], 'can:routing.update')
        ->middlewareFor('destroy', 'can:routing.delete');
    Route::get('tools', [ToolController::class, 'index'])
        ->middleware('can:tool.view_any')->name('tools.index');

    // ---- Commercial ----------------------------------------------------------------
    Route::resource('inquiries', InquiryController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:inquiry.view_any')
        ->middlewareFor(['create', 'store'], 'can:inquiry.create')
        ->middlewareFor(['edit', 'update'], 'can:inquiry.update');

    Route::post('inquiries/{inquiry}/transition', [InquiryController::class, 'transition'])
        ->middleware('can:inquiry.view')->name('inquiries.transition');

    Route::resource('quotations', QuotationController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:quotation.view_any')
        ->middlewareFor(['create', 'store'], 'can:quotation.create')
        ->middlewareFor(['edit', 'update'], 'can:quotation.update');

    Route::post('quotations/{quotation}/duplicate', [QuotationController::class, 'duplicate'])
        ->middleware('can:quotation.create')->name('quotations.duplicate');

    Route::post('quotations/{quotation}/transition', [QuotationController::class, 'transition'])
        ->middleware('can:quotation.view')->name('quotations.transition');

    // Contract pricing: a header with quantity-break lines, so not a generic reference list.
    Route::resource('price-lists', PriceListController::class)
        ->parameters(['price-lists' => 'priceList'])
        ->middlewareFor(['index', 'show'], 'can:price_list.view_any')
        ->middlewareFor(['create', 'store'], 'can:price_list.create')
        ->middlewareFor(['edit', 'update'], 'can:price_list.update')
        ->middlewareFor('destroy', 'can:price_list.delete');
    Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])
        ->middleware('can:sales_order.create')->name('quotations.convert');

    Route::post('cost-sheets/calculate', [CostSheetController::class, 'calculate'])
        ->middleware('can:cost_sheet.view_any')->name('cost-sheets.calculate');
    Route::put('cost-sheets/{costSheet}', [CostSheetController::class, 'update'])
        ->middleware('can:cost_sheet.update')->name('cost-sheets.update');

    Route::resource('sales-orders', SalesOrderController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:sales_order.view_any')
        ->middlewareFor(['create', 'store'], 'can:sales_order.create')
        ->middlewareFor(['edit', 'update'], 'can:sales_order.update');

    Route::post('sales-orders/{salesOrder}/transition', [SalesOrderController::class, 'transition'])
        ->middleware('can:sales_order.view')->name('sales-orders.transition');

    // ---- Supply --------------------------------------------------------------------
    Route::resource('purchase-requisitions', PurchaseRequisitionController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:purchase_requisition.view_any')
        ->middlewareFor(['create', 'store'], 'can:purchase_requisition.create')
        ->middlewareFor(['edit', 'update'], 'can:purchase_requisition.update');

    Route::post('purchase-requisitions/{purchaseRequisition}/transition', [PurchaseRequisitionController::class, 'transition'])
        ->middleware('can:purchase_requisition.view')->name('purchase-requisitions.transition');

    Route::resource('rfqs', SupplierRfqController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:rfq.view_any')
        ->middlewareFor(['create', 'store'], 'can:rfq.create')
        ->middlewareFor(['edit', 'update'], 'can:rfq.update');
    Route::post('rfqs/{rfq}/transition', [SupplierRfqController::class, 'transition'])
        ->middleware('can:rfq.view')->name('rfqs.transition');
    Route::get('rfqs/{rfq}/compare', [SupplierRfqController::class, 'compare'])
        ->middleware('can:rfq.view')->name('rfqs.compare');
    Route::post('rfqs/{rfq}/quotations', [SupplierRfqController::class, 'storeQuotation'])
        ->middleware('can:rfq.update')->name('rfqs.quotations.store');
    Route::post('rfqs/{rfq}/select', [SupplierRfqController::class, 'select'])
        ->middleware('can:rfq.update')->name('rfqs.select');
    Route::post('rfqs/{rfq}/purchase-order', [SupplierRfqController::class, 'storePurchaseOrder'])
        ->middleware('can:purchase_order.create')->name('rfqs.purchase-order');

    Route::resource('purchase-orders', PurchaseOrderController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:purchase_order.view_any')
        ->middlewareFor(['create', 'store'], 'can:purchase_order.create')
        ->middlewareFor(['edit', 'update'], 'can:purchase_order.update');

    Route::post('purchase-orders/{purchaseOrder}/transition', [PurchaseOrderController::class, 'transition'])
        ->middleware('can:purchase_order.view')->name('purchase-orders.transition');

    Route::get('grns', [GrnController::class, 'index'])
        ->middleware('can:grn.view_any')->name('grns.index');
    Route::get('grns/create', [GrnController::class, 'create'])
        ->middleware('can:grn.create')->name('grns.create');
    Route::post('grns', [GrnController::class, 'store'])
        ->middleware('can:grn.create')->name('grns.store');
    Route::get('grns/{grn}', [GrnController::class, 'show'])
        ->middleware('can:grn.view')->name('grns.show');
    Route::post('grns/{grn}/post', [GrnController::class, 'post'])
        ->middleware('can:grn.post')->name('grns.post');

    // ---- Inventory -----------------------------------------------------------------
    Route::get('stock', StockEnquiryController::class)
        ->middleware('can:stock_lot.view_any')->name('stock.index');
    Route::get('lots', [StockLotController::class, 'index'])
        ->middleware('can:stock_lot.view_any')->name('lots.index');
    Route::get('lots/{lot}', [StockLotController::class, 'show'])
        ->middleware('can:stock_lot.view')->name('lots.show');

    Route::get('material-issues', [MaterialIssueController::class, 'index'])
        ->middleware('can:stock_issue.view_any')->name('material-issues.index');
    Route::get('material-issues/create', [MaterialIssueController::class, 'create'])
        ->middleware('can:stock_issue.create')->name('material-issues.create');
    Route::post('material-issues', [MaterialIssueController::class, 'store'])
        ->middleware('can:stock_issue.post')->name('material-issues.store');
    Route::get('material-issues/suggest', [MaterialIssueController::class, 'suggest'])
        ->middleware('can:stock_issue.create')->name('material-issues.suggest');
    Route::get('material-issues/returnable', [MaterialIssueController::class, 'returnable'])
        ->middleware('can:stock_issue.create')->name('material-issues.returnable');

    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])
        ->middleware('can:stock_adjustment.view_any')->name('stock-adjustments.index');
    Route::get('stock-adjustments/create', [StockAdjustmentController::class, 'create'])
        ->middleware('can:stock_adjustment.create')->name('stock-adjustments.create');
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware('can:stock_adjustment.create')->name('stock-adjustments.store');
    Route::get('stock-adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])
        ->middleware('can:stock_adjustment.view')->name('stock-adjustments.show');
    Route::get('stock-adjustments/{adjustment}/edit', [StockAdjustmentController::class, 'edit'])
        ->middleware('can:stock_adjustment.update')->name('stock-adjustments.edit');
    Route::put('stock-adjustments/{adjustment}', [StockAdjustmentController::class, 'update'])
        ->middleware('can:stock_adjustment.update')->name('stock-adjustments.update');
    Route::post('stock-adjustments/{adjustment}/transition', [StockAdjustmentController::class, 'transition'])
        ->middleware('can:stock_adjustment.view')->name('stock-adjustments.transition');

    Route::get('stock-transfers', [StockTransferController::class, 'index'])
        ->middleware('can:stock_transfer.view_any')->name('stock-transfers.index');
    Route::get('stock-transfers/create', [StockTransferController::class, 'create'])
        ->middleware('can:stock_transfer.create')->name('stock-transfers.create');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])
        ->middleware('can:stock_transfer.create')->name('stock-transfers.store');
    Route::get('stock-transfers/{transfer}', [StockTransferController::class, 'show'])
        ->middleware('can:stock_transfer.view')->name('stock-transfers.show');
    Route::get('stock-transfers/{transfer}/edit', [StockTransferController::class, 'edit'])
        ->middleware('can:stock_transfer.update')->name('stock-transfers.edit');
    Route::put('stock-transfers/{transfer}', [StockTransferController::class, 'update'])
        ->middleware('can:stock_transfer.update')->name('stock-transfers.update');
    Route::post('stock-transfers/{transfer}/transition', [StockTransferController::class, 'transition'])
        ->middleware('can:stock_transfer.view')->name('stock-transfers.transition');

    Route::get('physical-counts', [PhysicalCountController::class, 'index'])
        ->middleware('can:physical_count.view_any')->name('physical-counts.index');
    Route::get('physical-counts/create', [PhysicalCountController::class, 'create'])
        ->middleware('can:physical_count.create')->name('physical-counts.create');
    Route::post('physical-counts', [PhysicalCountController::class, 'store'])
        ->middleware('can:physical_count.create')->name('physical-counts.store');
    Route::get('physical-counts/{count}', [PhysicalCountController::class, 'show'])
        ->middleware('can:physical_count.view')->name('physical-counts.show');
    Route::get('physical-counts/{count}/edit', [PhysicalCountController::class, 'edit'])
        ->middleware('can:physical_count.update')->name('physical-counts.edit');
    Route::put('physical-counts/{count}', [PhysicalCountController::class, 'update'])
        ->middleware('can:physical_count.update')->name('physical-counts.update');
    Route::post('physical-counts/{count}/transition', [PhysicalCountController::class, 'transition'])
        ->middleware('can:physical_count.view')->name('physical-counts.transition');
    Route::get('physical-counts/{count}/print', [PhysicalCountController::class, 'print'])
        ->middleware('can:physical_count.view')->name('physical-counts.print');

    // ---- Operations ----------------------------------------------------------------
    Route::get('planning', PlanningBoardController::class)
        ->middleware('can:production_plan.view_any')->name('planning.index');
    Route::get('mrp', [MrpController::class, 'index'])
        ->middleware('can:mrp.view_any')->name('mrp.index');
    Route::post('mrp/run', [MrpController::class, 'run'])
        ->middleware('can:mrp.run')->name('mrp.run');

    Route::get('job-cards', [JobCardController::class, 'index'])
        ->middleware('can:job_card.view_any')->name('job-cards.index');
    Route::get('job-cards/create', [JobCardController::class, 'create'])
        ->middleware('can:job_card.create')->name('job-cards.create');
    Route::post('job-cards', [JobCardController::class, 'store'])
        ->middleware('can:job_card.create')->name('job-cards.store');
    Route::get('job-cards/{jobCard}', [JobCardController::class, 'show'])
        ->middleware('can:job_card.view')->name('job-cards.show');
    Route::post('job-cards/{jobCard}/transition', [JobCardController::class, 'transition'])
        ->middleware('can:job_card.view')->name('job-cards.transition');
    // P0-3 — production output enters finished-goods stock through this one door.
    Route::post('job-cards/{jobCard}/fg-receipts', [FgReceiptController::class, 'store'])
        ->middleware('can:fg_receipt.post')->name('job-cards.fg-receipts.store');

    // ---- Assurance -----------------------------------------------------------------
    Route::get('qc-inspections', [QcInspectionController::class, 'index'])
        ->middleware('can:qc_inspection.view_any')->name('qc-inspections.index');
    Route::get('qc-inspections/create', [QcInspectionController::class, 'create'])
        ->middleware('can:qc_inspection.create')->name('qc-inspections.create');
    Route::post('qc-inspections', [QcInspectionController::class, 'store'])
        ->middleware('can:qc_inspection.post')->name('qc-inspections.store');
    Route::get('qc-inspections/{inspection}', [QcInspectionController::class, 'show'])
        ->middleware('can:qc_inspection.view')->name('qc-inspections.show');

    Route::get('ncrs', [NcrController::class, 'index'])
        ->middleware('can:ncr.view_any')->name('ncrs.index');
    Route::get('ncrs/{ncr}', [NcrController::class, 'show'])
        ->middleware('can:ncr.view')->name('ncrs.show');
    Route::post('ncrs/{ncr}/assign', [NcrController::class, 'assign'])
        ->middleware('can:ncr.update')->name('ncrs.assign');
    Route::post('ncrs/{ncr}/investigate', [NcrController::class, 'investigate'])
        ->middleware('can:ncr.update')->name('ncrs.investigate');
    Route::post('ncrs/{ncr}/disposition', [NcrController::class, 'disposition'])
        ->middleware('can:ncr.update')->name('ncrs.disposition');
    Route::post('ncrs/{ncr}/verify', [NcrController::class, 'verify'])
        ->middleware('can:ncr.close')->name('ncrs.verify');
    Route::post('ncrs/{ncr}/close', [NcrController::class, 'close'])
        ->middleware('can:ncr.close')->name('ncrs.close');

    Route::get('lab', [LabController::class, 'index'])
        ->middleware('can:test_report.view_any')->name('lab.index');
    Route::get('lab/reports/create', [LabController::class, 'create'])
        ->middleware('can:test_report.create')->name('lab.create');
    Route::post('lab/reports', [LabController::class, 'store'])
        ->middleware('can:test_report.create')->name('lab.store');
    Route::get('lab/reports/{report}', [LabController::class, 'show'])
        ->middleware('can:test_report.view')->name('lab.show');
    Route::post('lab/reports/{report}/transition', [LabController::class, 'transition'])
        ->middleware('can:test_report.view')->name('lab.transition');

    Route::get('compliance', [ComplianceController::class, 'index'])
        ->middleware('can:coc.view_any')->name('compliance.index');
    Route::get('compliance/reconciliation', [ComplianceController::class, 'reconciliation'])
        ->middleware('can:coc.reconcile')->name('compliance.reconciliation');

    // ---- Fulfilment ----------------------------------------------------------------
    Route::get('packing-lists', [PackingListController::class, 'index'])
        ->middleware('can:packing_list.view_any')->name('packing-lists.index');
    Route::get('packing-lists/create', [PackingListController::class, 'create'])
        ->middleware('can:packing_list.create')->name('packing-lists.create');
    Route::post('packing-lists', [PackingListController::class, 'store'])
        ->middleware('can:packing_list.create')->name('packing-lists.store');
    Route::get('packing-lists/{packingList}', [PackingListController::class, 'show'])
        ->middleware('can:packing_list.view')->name('packing-lists.show');
    // Targets carry their own permission inside the state machine (pack / delete / issue).
    Route::post('packing-lists/{packingList}/transition', [PackingListController::class, 'transition'])
        ->middleware('can:packing_list.view')->name('packing-lists.transition');
    Route::post('packing-lists/{packingList}/cartons', [PackingListController::class, 'storeCarton'])
        ->middleware('can:packing_list.update')->name('packing-lists.cartons.store');
    Route::delete('packing-lists/{packingList}/cartons/{carton}', [PackingListController::class, 'destroyCarton'])
        ->middleware('can:packing_list.update')->name('packing-lists.cartons.destroy');
    Route::post('packing-lists/{packingList}/cartons/{carton}/contents', [PackingListController::class, 'storeContent'])
        ->middleware('can:packing_list.update')->name('packing-lists.contents.store');
    Route::delete('packing-lists/{packingList}/cartons/{carton}/contents/{content}', [PackingListController::class, 'destroyContent'])
        ->middleware('can:packing_list.update')->name('packing-lists.contents.destroy');

    Route::get('delivery-challans', [DeliveryChallanController::class, 'index'])
        ->middleware('can:delivery_challan.view_any')->name('delivery-challans.index');
    Route::post('delivery-challans', [DeliveryChallanController::class, 'store'])
        ->middleware('can:delivery_challan.create')->name('delivery-challans.store');
    Route::get('delivery-challans/{deliveryChallan}', [DeliveryChallanController::class, 'show'])
        ->middleware('can:delivery_challan.view')->name('delivery-challans.show');
    Route::post('delivery-challans/{deliveryChallan}/transition', [DeliveryChallanController::class, 'transition'])
        ->middleware('can:delivery_challan.view')->name('delivery-challans.transition');
    Route::get('trips', [TripController::class, 'index'])
        ->middleware('can:trip.access')->name('trips.index');
    Route::get('trips/create', [TripController::class, 'create'])
        ->middleware('can:trip.create')->name('trips.create');
    Route::post('trips', [TripController::class, 'store'])
        ->middleware('can:trip.create')->name('trips.store');
    Route::get('trips/{trip}', [TripController::class, 'show'])
        ->middleware('can:trip.view')->name('trips.show');
    Route::post('trips/{trip}/start', [TripController::class, 'start'])
        ->middleware('can:trip.start')->name('trips.start');
    Route::post('trips/{trip}/complete', [TripController::class, 'complete'])
        ->middleware('can:trip.complete')->name('trips.complete');
    Route::post('trips/{trip}/stops/{stop}/deliver', [TripController::class, 'deliver'])
        ->middleware('can:trip_stop.update')->name('trips.stops.deliver');

    /*
     * ---- Trade finance & import ------------------------------------------------------
     * Raw material is imported (00-overview §2), so the cost of a kilo of yarn is the
     * supplier's rate plus freight, duty and the C&F bill — none of which are known when the
     * PO is raised. These screens carry the credit and the consignment between the two.
     */
    Route::resource('letters-of-credit', LetterOfCreditController::class)
        ->parameters(['letters-of-credit' => 'letterOfCredit'])
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:letter_of_credit.view_any')
        ->middlewareFor(['create', 'store'], 'can:letter_of_credit.create')
        ->middlewareFor(['edit', 'update'], 'can:letter_of_credit.update');
    Route::delete('letters-of-credit/{letterOfCredit}', [LetterOfCreditController::class, 'destroy'])
        ->middleware('can:letter_of_credit.delete')->name('letters-of-credit.destroy');
    Route::post('letters-of-credit/{letterOfCredit}/transition', [LetterOfCreditController::class, 'transition'])
        ->middleware('can:letter_of_credit.open')->name('letters-of-credit.transition');
    Route::post('letters-of-credit/{letterOfCredit}/amend', [LetterOfCreditController::class, 'amend'])
        ->middleware('can:letter_of_credit.amend')->name('letters-of-credit.amend');
    Route::post('letters-of-credit/{letterOfCredit}/orders', [LetterOfCreditController::class, 'attachOrder'])
        ->middleware('can:letter_of_credit.update')->name('letters-of-credit.orders.attach');
    Route::delete('letters-of-credit/{letterOfCredit}/orders/{poId}', [LetterOfCreditController::class, 'detachOrder'])
        ->middleware('can:letter_of_credit.update')->name('letters-of-credit.orders.detach');

    Route::resource('import-shipments', ImportShipmentController::class)
        ->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:import_shipment.view_any')
        ->middlewareFor(['create', 'store'], 'can:import_shipment.create')
        ->middlewareFor(['edit', 'update'], 'can:import_shipment.update');
    Route::delete('import-shipments/{importShipment}', [ImportShipmentController::class, 'destroy'])
        ->middleware('can:import_shipment.delete')->name('import-shipments.destroy');
    Route::post('import-shipments/{importShipment}/transition', [ImportShipmentController::class, 'transition'])
        ->middleware('can:import_shipment.update')->name('import-shipments.transition');
    Route::post('import-shipments/{importShipment}/costs', [ImportShipmentController::class, 'addCost'])
        ->middleware('can:import_shipment.cost')->name('import-shipments.costs.store');
    Route::delete('import-shipments/{importShipment}/costs/{cost}', [ImportShipmentController::class, 'removeCost'])
        ->middleware('can:import_shipment.cost')->name('import-shipments.costs.destroy');
    Route::post('import-shipments/{importShipment}/receipts', [ImportShipmentController::class, 'linkReceipt'])
        ->middleware('can:import_shipment.update')->name('import-shipments.receipts.link');
    Route::delete('import-shipments/{importShipment}/receipts/{grnId}', [ImportShipmentController::class, 'unlinkReceipt'])
        ->middleware('can:import_shipment.update')->name('import-shipments.receipts.unlink');
    // BR-36 — the button that turns a pile of bills into a lot cost. Its own permission:
    // it rewrites inventory valuation.
    Route::post('import-shipments/{importShipment}/allocate', [ImportShipmentController::class, 'allocate'])
        ->middleware('can:import_shipment.allocate')->name('import-shipments.allocate');

    // ---- Money ---------------------------------------------------------------------
    Route::get('invoices', [SalesInvoiceController::class, 'index'])
        ->middleware('can:sales_invoice.view_any')->name('invoices.index');
    Route::post('invoices', [SalesInvoiceController::class, 'store'])
        ->middleware('can:sales_invoice.create')->name('invoices.store');
    Route::get('invoices/{invoice}', [SalesInvoiceController::class, 'show'])
        ->middleware('can:sales_invoice.view')->name('invoices.show');
    // Issue and cancel carry their own permission inside the state machine.
    Route::post('invoices/{invoice}/transition', [SalesInvoiceController::class, 'transition'])
        ->middleware('can:sales_invoice.view')->name('invoices.transition');

    // P2-1 — credit notes: approve and apply carry their own permission in the state machine.
    Route::get('credit-notes', [CreditNoteController::class, 'index'])
        ->middleware('can:credit_note.view_any')->name('credit-notes.index');
    Route::post('credit-notes', [CreditNoteController::class, 'store'])
        ->middleware('can:credit_note.create')->name('credit-notes.store');
    Route::get('credit-notes/{creditNote}', [CreditNoteController::class, 'show'])
        ->middleware('can:credit_note.view')->name('credit-notes.show');
    Route::post('credit-notes/{creditNote}/transition', [CreditNoteController::class, 'transition'])
        ->middleware('can:credit_note.view')->name('credit-notes.transition');

    Route::get('receipts', [ReceiptController::class, 'index'])
        ->middleware('can:receipt.view_any')->name('receipts.index');
    Route::post('receipts', [ReceiptController::class, 'store'])
        ->middleware('can:receipt.allocate')->name('receipts.store');

    // FN-4 — supplier bills: three-way match, approve, pay.
    Route::get('supplier-bills', [SupplierBillController::class, 'index'])
        ->middleware('can:supplier_bill.view_any')->name('supplier-bills.index');
    Route::get('supplier-bills/create', [SupplierBillController::class, 'create'])
        ->middleware('can:supplier_bill.create')->name('supplier-bills.create');
    Route::post('supplier-bills', [SupplierBillController::class, 'store'])
        ->middleware('can:supplier_bill.create')->name('supplier-bills.store');
    Route::get('supplier-bills/{supplierBill}', [SupplierBillController::class, 'show'])
        ->middleware('can:supplier_bill.view')->name('supplier-bills.show');
    Route::post('supplier-bills/{supplierBill}/transition', [SupplierBillController::class, 'transition'])
        ->middleware('can:supplier_bill.view')->name('supplier-bills.transition');

    // FN-5 — supplier payments: allocate against approved bills.
    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('can:payment.view_any')->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('can:payment.allocate')->name('payments.store');

    Route::resource('expenses', ExpenseController::class)
        ->except(['show', 'destroy'])
        ->middlewareFor('index', 'can:expense.view_any')
        ->middlewareFor(['create', 'store'], 'can:expense.create')
        ->middlewareFor(['edit', 'update'], 'can:expense.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('can:expense.delete')->name('expenses.destroy');
    // Approval and payment are checked again inside — the route gate cannot express "approve
    // needs expense.approve, pay needs expense.pay, and never your own document".
    Route::post('expenses/{expense}/transition', [ExpenseController::class, 'transition'])
        ->middleware('can:expense.view_any')->name('expenses.transition');

    /*
     * Bulk transitions. Every document still goes through its own state machine one at a
     * time — this is a loop, not a shortcut past the guards.
     */
    Route::post('bulk/{resource}/transition', BulkTransitionController::class)
        ->middleware('can:purchase_requisition.view_any')->name('bulk.transition');

    /*
     * Printable documents. Blade, not Inertia: a print view wants no shell and no JavaScript,
     * and the browser's own PDF writer is better than a library that renders something subtly
     * different from what the screen showed.
     */
    Route::get('quotations/{quotation}/print', [DocumentPrintController::class, 'quotation'])
        ->middleware('can:quotation.view')->name('quotations.print');
    Route::get('purchase-orders/{purchaseOrder}/print', [DocumentPrintController::class, 'purchaseOrder'])
        ->middleware('can:purchase_order.view')->name('purchase-orders.print');
    Route::get('job-cards/{jobCard}/print', [DocumentPrintController::class, 'jobCard'])
        ->middleware('can:job_card.view')->name('job-cards.print');

    /*
     * CSV, XLSX and PDF export. One controller for every list; the `.export` permission is
     * checked per resource inside, because it is not the same right as reading the list on
     * screen.
     */
    Route::get('exports/{resource}/columns', [ExportController::class, 'columns'])->name('exports.columns');
    Route::get('exports/{resource}', ExportController::class)->name('exports');

    /*
     * Spreadsheet import, for the master-data lists only. `.import` is checked per resource
     * inside the controller for the same reason `.export` is, and is a right of its own:
     * loading four hundred suppliers in one upload is not the same act as adding one.
     */
    Route::get('imports/{resource}/fields', [ImportController::class, 'fields'])->name('imports.fields');
    Route::get('imports/{resource}/sample', [ImportController::class, 'sample'])->name('imports.sample');
    Route::post('imports/{resource}', ImportController::class)->name('imports');

    // The ⌘K palette. Every source inside is permission-checked individually.
    Route::get('search', SearchController::class)->name('search');

    /*
     * ---- Setup ---------------------------------------------------------------------
     * The lookup lists behind every dropdown. One controller drives all of them from
     * ReferenceRegistry; authorisation is per list, resolved inside the controller, because
     * a single `can:` on the route cannot express "uom.update for one list, tax.update for
     * the next". Route-level `can:reference_data.view_any` is the floor, not the whole check.
     */
    Route::get('setup', [ReferenceController::class, 'hub'])
        ->middleware('can:reference_data.view_any')->name('setup.index');
    Route::get('setup/{reference}', [ReferenceController::class, 'index'])
        ->middleware('can:reference_data.view_any')->name('setup.reference');
    Route::post('setup/{reference}', [ReferenceController::class, 'store'])
        ->middleware('can:reference_data.view_any')->name('setup.reference.store');
    Route::put('setup/{reference}/{id}', [ReferenceController::class, 'update'])
        ->middleware('can:reference_data.view_any')->name('setup.reference.update');
    Route::delete('setup/{reference}/{id}', [ReferenceController::class, 'destroy'])
        ->middleware('can:reference_data.view_any')->name('setup.reference.destroy');

    // ---- Administration ------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:user.view_any')->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('can:user.create')->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])
            ->middleware('can:user.update')->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->middleware('can:user.delete')->name('users.destroy');
        Route::post('users/{user}/roles', [UserController::class, 'updateRoles'])
            ->middleware('can:user.assign_role')->name('users.roles');

        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('can:role.view_any')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('can:role.create')->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])
            ->middleware('can:role.update')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('can:role.delete')->name('roles.destroy');

        Route::get('organisation', [OrganisationController::class, 'index'])
            ->middleware('can:setting.view_any')->name('organisation.index');
        Route::put('organisation', [OrganisationController::class, 'update'])
            ->middleware('can:setting.update')->name('organisation.update');
        Route::post('organisation/branding', [OrganisationController::class, 'updateBranding'])
            ->middleware('can:setting.update')->name('organisation.branding');
        Route::delete('organisation/branding', [OrganisationController::class, 'destroyBranding'])
            ->middleware('can:setting.update')->name('organisation.branding.destroy');

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('can:setting.view_any')->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('can:setting.update')->name('settings.update');

        Route::get('number-sequences', [NumberSequenceController::class, 'index'])
            ->middleware('can:number_sequence.view_any')->name('number-sequences.index');

        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:audit_log.view_any')->name('audit-log.index');
    });
});
