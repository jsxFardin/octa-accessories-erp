<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Modules\Compliance\Http\Controllers\ComplianceController;
use App\Modules\Costing\Http\Controllers\CostSheetController;
use App\Modules\Dispatch\Http\Controllers\DeliveryChallanController;
use App\Modules\Dispatch\Http\Controllers\PackingListController;
use App\Modules\Dispatch\Http\Controllers\TripController;
use App\Modules\Finance\Http\Controllers\ReceiptController;
use App\Modules\Finance\Http\Controllers\SalesInvoiceController;
use App\Modules\Inventory\Http\Controllers\MaterialIssueController;
use App\Modules\Inventory\Http\Controllers\StockEnquiryController;
use App\Modules\Inventory\Http\Controllers\StockLotController;
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
use App\Modules\Product\Http\Controllers\ArtworkController;
use App\Modules\Product\Http\Controllers\ArtworkVersionController;
use App\Modules\Product\Http\Controllers\BomController;
use App\Modules\Product\Http\Controllers\ProductController;
use App\Modules\Product\Http\Controllers\ProductSpecController;
use App\Modules\Product\Http\Controllers\RoutingController;
use App\Modules\Product\Http\Controllers\ToolController;
use App\Modules\Quality\Http\Controllers\LabController;
use App\Modules\Quality\Http\Controllers\QcInspectionController;
use App\Modules\Sales\Http\Controllers\InquiryController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Support\Http\LandingPage;
use App\Support\Platform\Http\Controllers\AuditLogController;
use App\Support\Platform\Http\Controllers\NumberSequenceController;
use App\Support\Platform\Http\Controllers\SettingController;
use App\Support\Platform\Http\Controllers\UserController;
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

    Route::get('dashboard', DashboardController::class)
        ->middleware('can:report.dashboard')
        ->name('dashboard');

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
    Route::post('artworks/{artwork}/versions', [ArtworkVersionController::class, 'store'])
        ->middleware('can:artwork.create')->name('artworks.versions.store');
    Route::post('artwork-versions/{version}/transition', [ArtworkVersionController::class, 'transition'])
        ->middleware('can:artwork.submit')->name('artwork-versions.transition');

    Route::post('products/{product}/boms', [BomController::class, 'store'])
        ->middleware('can:bom.create')->name('products.boms.store');
    Route::post('boms/{bom}/activate', [BomController::class, 'activate'])
        ->middleware('can:bom.activate')->name('boms.activate');

    Route::get('routings', [RoutingController::class, 'index'])
        ->middleware('can:routing.view_any')->name('routings.index');
    Route::get('tools', [ToolController::class, 'index'])
        ->middleware('can:tool.view_any')->name('tools.index');

    // ---- Commercial ----------------------------------------------------------------
    Route::resource('inquiries', InquiryController::class)->except(['edit', 'destroy'])
        ->middlewareFor(['index', 'show'], 'can:inquiry.view_any')
        ->middlewareFor(['create', 'store'], 'can:inquiry.create')
        ->middlewareFor('update', 'can:inquiry.update');

    Route::resource('quotations', QuotationController::class)->except(['destroy'])
        ->middlewareFor(['index', 'show'], 'can:quotation.view_any')
        ->middlewareFor(['create', 'store'], 'can:quotation.create')
        ->middlewareFor(['edit', 'update'], 'can:quotation.update');

    Route::post('quotations/{quotation}/transition', [QuotationController::class, 'transition'])
        ->middleware('can:quotation.send')->name('quotations.transition');
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
        ->middleware('can:sales_order.confirm')->name('sales-orders.transition');

    // ---- Supply --------------------------------------------------------------------
    Route::get('purchase-requisitions', [PurchaseRequisitionController::class, 'index'])
        ->middleware('can:purchase_requisition.view_any')->name('purchase-requisitions.index');
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])
        ->middleware('can:purchase_order.view_any')->name('purchase-orders.index');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->middleware('can:purchase_order.view')->name('purchase-orders.show');

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
        ->middleware('can:job_card.update')->name('job-cards.transition');

    // ---- Assurance -----------------------------------------------------------------
    Route::get('qc-inspections', [QcInspectionController::class, 'index'])
        ->middleware('can:qc_inspection.view_any')->name('qc-inspections.index');
    Route::get('qc-inspections/create', [QcInspectionController::class, 'create'])
        ->middleware('can:qc_inspection.create')->name('qc-inspections.create');
    Route::post('qc-inspections', [QcInspectionController::class, 'store'])
        ->middleware('can:qc_inspection.post')->name('qc-inspections.store');
    Route::get('qc-inspections/{inspection}', [QcInspectionController::class, 'show'])
        ->middleware('can:qc_inspection.view')->name('qc-inspections.show');

    Route::get('lab', [LabController::class, 'index'])
        ->middleware('can:test_report.view_any')->name('lab.index');

    Route::get('compliance', [ComplianceController::class, 'index'])
        ->middleware('can:coc.view_any')->name('compliance.index');
    Route::get('compliance/reconciliation', [ComplianceController::class, 'reconciliation'])
        ->middleware('can:coc.reconcile')->name('compliance.reconciliation');

    // ---- Fulfilment ----------------------------------------------------------------
    Route::get('packing-lists', [PackingListController::class, 'index'])
        ->middleware('can:packing_list.view_any')->name('packing-lists.index');
    Route::get('delivery-challans', [DeliveryChallanController::class, 'index'])
        ->middleware('can:delivery_challan.view_any')->name('delivery-challans.index');
    Route::get('trips', [TripController::class, 'index'])
        ->middleware('can:trip.access')->name('trips.index');

    // ---- Money ---------------------------------------------------------------------
    Route::get('invoices', [SalesInvoiceController::class, 'index'])
        ->middleware('can:sales_invoice.view_any')->name('invoices.index');
    Route::get('receipts', [ReceiptController::class, 'index'])
        ->middleware('can:receipt.view_any')->name('receipts.index');

    // ---- Administration ------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:user.view_any')->name('users.index');
        Route::post('users/{user}/roles', [UserController::class, 'updateRoles'])
            ->middleware('can:user.assign_role')->name('users.roles');

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
