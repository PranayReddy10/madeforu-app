package com.madeforu.sales.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Wire models.
 *
 * Every field the server may omit has a default, so adding a field on the
 * server never crashes an older build in a partner's pocket. The parser is
 * configured with ignoreUnknownKeys for the same reason, in the other
 * direction.
 */

@Serializable
data class Admin(
    val id: Int = 0,
    val name: String = "",
    val phone: String = "",
)

@Serializable
data class LoginResponse(
    val token: String = "",
    @SerialName("expires_at") val expiresAt: String = "",
    val admin: Admin = Admin(),
)

@Serializable
data class Product(
    val id: Int = 0,
    val name: String = "",
    val price: Double = 0.0,
    @SerialName("unit_cost") val unitCost: Double = 0.0,
    val margin: Double = 0.0,
    @SerialName("margin_pct") val marginPct: Double? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("sort_order") val sortOrder: Int = 0,
    @SerialName("qty_on_hand") val qtyOnHand: Double = 0.0,
    // Set on the website's Products page; these are what the public
    // menu.php catalogue and share.php use.
    @SerialName("image_url") val imageUrl: String = "",
    @SerialName("product_url") val productUrl: String = "",
)

@Serializable
data class Event(
    val id: Int = 0,
    val name: String = "",
    @SerialName("is_paid") val isPaid: Boolean = false,
    @SerialName("entry_cost") val entryCost: Double = 0.0,
    @SerialName("extra_cost") val extraCost: Double = 0.0,
    @SerialName("start_date") val startDate: String? = null,
    @SerialName("end_date") val endDate: String? = null,
    @SerialName("is_active") val isActive: Boolean = true,
    val notes: String? = null,
    @SerialName("order_count") val orderCount: Int = 0,
    val revenue: Double = 0.0,
)

@Serializable
data class Partner(
    val id: Int = 0,
    val name: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
)

@Serializable
data class PaymentMode(val key: String = "cash", val label: String = "Cash")

@Serializable
data class Category(val id: Int = 0, val name: String = "")

@Serializable
data class Settings(
    @SerialName("business_name") val businessName: String = "MadeForU",
    @SerialName("business_tag") val businessTag: String = "",
    @SerialName("business_addr") val businessAddr: String = "",
    @SerialName("business_phone") val businessPhone: String = "",
    @SerialName("business_email") val businessEmail: String = "",
    @SerialName("business_site") val businessSite: String = "",
    val gstin: String = "",
    @SerialName("upi_id") val upiId: String = "",
    @SerialName("upi_name") val upiName: String = "",
    @SerialName("bill_prefix") val billPrefix: String = "MFU",
    @SerialName("bill_footer") val billFooter: String = "",
    @SerialName("bill_terms") val billTerms: String = "",
)

@Serializable
data class Bootstrap(
    val admin: Admin = Admin(),
    val products: List<Product> = emptyList(),
    val events: List<Event> = emptyList(),
    val partners: List<Partner> = emptyList(),
    val categories: List<Category> = emptyList(),
    @SerialName("payment_modes") val paymentModes: List<PaymentMode> = emptyList(),
    val settings: Settings = Settings(),
)

@Serializable
data class OrderItem(
    val id: Int = 0,
    val item: String = "",
    val quantity: Int = 0,
    @SerialName("unit_price") val unitPrice: Double = 0.0,
    @SerialName("line_total") val lineTotal: Double = 0.0,
)

@Serializable
data class Payment(
    val id: Int = 0,
    val amount: Double = 0.0,
    val mode: String = "cash",
    val note: String? = null,
    @SerialName("taken_by") val takenBy: String? = null,
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class WhatsAppTexts(
    @SerialName("order_text") val orderText: String = "",
    @SerialName("order_link") val orderLink: String? = null,
    @SerialName("dispatch_text") val dispatchText: String? = null,
    @SerialName("dispatch_link") val dispatchLink: String? = null,
)

@Serializable
data class Order(
    val id: Int = 0,
    @SerialName("order_no") val orderNo: String = "",
    val name: String = "",
    val phone: String = "",
    @SerialName("is_walk_in") val isWalkIn: Boolean = false,
    @SerialName("event_id") val eventId: Int? = null,
    @SerialName("event_name") val eventName: String? = null,
    val subtotal: Double = 0.0,
    @SerialName("extra_charge") val extraCharge: Double = 0.0,
    @SerialName("extra_charge_reason") val extraChargeReason: String? = null,
    val discount: Double = 0.0,
    @SerialName("discount_reason") val discountReason: String? = null,
    val total: Double = 0.0,
    @SerialName("paid_amount") val paidAmount: Double = 0.0,
    val balance: Double = 0.0,
    @SerialName("pay_status") val payStatus: String = "unpaid",
    @SerialName("is_ready") val isReady: Boolean = false,
    @SerialName("is_delivered") val isDelivered: Boolean = false,
    val awb: String? = null,
    @SerialName("track_url") val trackUrl: String? = null,
    @SerialName("dispatch_date") val dispatchDate: String? = null,
    val notes: String? = null,
    @SerialName("items_text") val itemsText: String? = null,
    @SerialName("created_at") val createdAt: String = "",
    @SerialName("created_by") val createdBy: String? = null,
    val items: List<OrderItem> = emptyList(),
    val payments: List<Payment> = emptyList(),
    @SerialName("has_bill") val hasBill: Boolean = false,
    val whatsapp: WhatsAppTexts? = null,
)

@Serializable
data class OrderSummary(
    val count: Int = 0,
    val total: Double = 0.0,
    val paid: Double = 0.0,
    val balance: Double = 0.0,
)

@Serializable
data class OrderListResponse(
    val orders: List<Order> = emptyList(),
    @SerialName("has_more") val hasMore: Boolean = false,
    val summary: OrderSummary = OrderSummary(),
)

@Serializable
data class OrderResponse(val order: Order = Order(), val message: String = "")

// ── Bills ──────────────────────────────────────────────────────────

@Serializable
data class BillCustomer(
    val name: String = "",
    val phone: String = "",
    @SerialName("is_walk_in") val isWalkIn: Boolean = false,
)

@Serializable
data class BillPayment(
    val amount: Double = 0.0,
    val mode: String = "cash",
    val note: String? = null,
    @SerialName("paid_at") val paidAt: String = "",
)

@Serializable
data class BillOrder(
    @SerialName("order_id") val orderId: Int = 0,
    @SerialName("order_no") val orderNo: String = "",
    @SerialName("order_date") val orderDate: String = "",
    val customer: BillCustomer = BillCustomer(),
    val channel: String = "",
    @SerialName("served_by") val servedBy: String? = null,
    val lines: List<OrderItem> = emptyList(),
    @SerialName("total_qty") val totalQty: Int = 0,
    val subtotal: Double = 0.0,
    @SerialName("extra_charge") val extraCharge: Double = 0.0,
    @SerialName("extra_charge_reason") val extraChargeReason: String? = null,
    val discount: Double = 0.0,
    @SerialName("discount_reason") val discountReason: String? = null,
    val total: Double = 0.0,
    val paid: Double = 0.0,
    val balance: Double = 0.0,
    @SerialName("pay_status") val payStatus: String = "unpaid",
    val payments: List<BillPayment> = emptyList(),
    val awb: String? = null,
    @SerialName("track_url") val trackUrl: String? = null,
    val notes: String? = null,
    @SerialName("amount_words") val amountWords: String = "",
)

@Serializable
data class BillBusiness(
    val name: String = "",
    val tag: String = "",
    val addr: String = "",
    val phone: String = "",
    val email: String = "",
    val site: String = "",
    val gstin: String = "",
)

@Serializable
data class Bill(
    @SerialName("bill_no") val billNo: String = "",
    val revision: Int = 1,
    @SerialName("issued_at") val issuedAt: String = "",
    @SerialName("issued_by") val issuedBy: String? = null,
    @SerialName("public_token") val publicToken: String = "",
    @SerialName("public_url") val publicUrl: String = "",
    val business: BillBusiness = BillBusiness(),
    val footer: String = "",
    val terms: String = "",
    @SerialName("upi_intent") val upiIntent: String? = null,
    val order: BillOrder = BillOrder(),
)

@Serializable
data class BillResponse(val bill: Bill = Bill(), val message: String = "")

@Serializable
data class BillListItem(
    @SerialName("bill_no") val billNo: String = "",
    val revision: Int = 1,
    @SerialName("issued_at") val issuedAt: String = "",
    @SerialName("order_id") val orderId: Int = 0,
    @SerialName("order_no") val orderNo: String = "",
    val customer: String = "",
    val total: Double = 0.0,
    val balance: Double = 0.0,
    @SerialName("pay_status") val payStatus: String = "unpaid",
    @SerialName("public_token") val publicToken: String = "",
)

@Serializable
data class BillListResponse(
    val bills: List<BillListItem> = emptyList(),
    @SerialName("has_more") val hasMore: Boolean = false,
)

// ── Statistics ─────────────────────────────────────────────────────

@Serializable
data class Headline(
    val orders: Int = 0,
    val revenue: Double = 0.0,
    val collected: Double = 0.0,
    val outstanding: Double = 0.0,
    @SerialName("avg_order") val avgOrder: Double = 0.0,
    val discount: Double = 0.0,
    val extra: Double = 0.0,
    val delivered: Int = 0,
    val ready: Int = 0,
    val pending: Int = 0,
    val dispatched: Int = 0,
    @SerialName("product_profit") val productProfit: Double = 0.0,
    val cogs: Double = 0.0,
    val expenses: Double = 0.0,
    @SerialName("business_profit") val businessProfit: Double = 0.0,
    @SerialName("margin_pct") val marginPct: Double? = null,
)

@Serializable
data class Change(
    val revenue: Double? = null,
    val orders: Double? = null,
    val collected: Double? = null,
    @SerialName("avg_order") val avgOrder: Double? = null,
)

@Serializable
data class Queues(
    @SerialName("to_make") val toMake: Int = 0,
    @SerialName("to_hand_over") val toHandOver: Int = 0,
    val owing: Int = 0,
    @SerialName("owed_amount") val owedAmount: Double = 0.0,
)

@Serializable
data class DateRange(val from: String = "", val to: String = "")

@Serializable
data class Dashboard(
    val range: DateRange = DateRange(),
    val previous: DateRange = DateRange(),
    val headline: Headline = Headline(),
    val change: Change = Change(),
    @SerialName("previous_headline") val previousHeadline: Headline = Headline(),
    val today: Headline = Headline(),
    val queues: Queues = Queues(),
)

@Serializable
data class SeriesPoint(
    val key: String = "",
    val label: String = "",
    val date: String = "",
    val orders: Int = 0,
    val revenue: Double = 0.0,
    val collected: Double = 0.0,
)

@Serializable
data class SeriesResponse(
    val range: DateRange = DateRange(),
    val bucket: String = "day",
    val points: List<SeriesPoint> = emptyList(),
)

@Serializable
data class ProductStat(
    val item: String = "",
    val qty: Int = 0,
    val revenue: Double = 0.0,
    val cost: Double = 0.0,
    val profit: Double = 0.0,
    @SerialName("margin_pct") val marginPct: Double? = null,
)

@Serializable
data class ModeStat(val mode: String = "", val count: Int = 0, val amount: Double = 0.0)

@Serializable
data class ChannelStat(val channel: String = "", val orders: Int = 0, val revenue: Double = 0.0)

@Serializable
data class HourStat(val hour: Int = 0, val orders: Int = 0, val revenue: Double = 0.0)

@Serializable
data class CustomerStat(
    val phone: String = "",
    val name: String = "",
    val orders: Int = 0,
    val spent: Double = 0.0,
)

@Serializable
data class Breakdown(
    val range: DateRange = DateRange(),
    val products: List<ProductStat> = emptyList(),
    val modes: List<ModeStat> = emptyList(),
    val channels: List<ChannelStat> = emptyList(),
    val hours: List<HourStat> = emptyList(),
    val customers: List<CustomerStat> = emptyList(),
)

// ── Finance ────────────────────────────────────────────────────────

@Serializable
data class PartnerFinance(
    val id: Int = 0,
    val name: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
    val paid: Double = 0.0,
    val credited: Double = 0.0,
    val debited: Double = 0.0,
    val adj: Double = 0.0,
    val contribution: Double = 0.0,
    val balance: Double = 0.0,
    @SerialName("fair_share") val fairShare: Double = 0.0,
    val gap: Double = 0.0,
    @SerialName("fair_balance") val fairBalance: Double = 0.0,
    @SerialName("balance_gap") val balanceGap: Double = 0.0,
    @SerialName("cred_gap") val credGap: Double = 0.0,
    @SerialName("adj_gap") val adjGap: Double = 0.0,
    @SerialName("inv_gap") val invGap: Double = 0.0,
)

@Serializable
data class FinanceTotals(
    val partners: Int = 0,
    val paid: Double = 0.0,
    val credited: Double = 0.0,
    val debited: Double = 0.0,
    val contribution: Double = 0.0,
    val balance: Double = 0.0,
    @SerialName("fair_share") val fairShare: Double = 0.0,
    @SerialName("fair_balance") val fairBalance: Double = 0.0,
    @SerialName("share_pct") val sharePct: Double = 0.0,
)

@Serializable
data class BusinessProfit(
    val revenue: Double = 0.0,
    val expenses: Double = 0.0,
    val profit: Double = 0.0,
    val distributed: Double = 0.0,
    val remaining: Double = 0.0,
)

@Serializable
data class SettleStep(
    @SerialName("from_id") val fromId: Int = 0,
    val from: String = "",
    @SerialName("to_id") val toId: Int = 0,
    val to: String = "",
    val amount: Double = 0.0,
)

@Serializable
data class Uncredited(val orders: Int = 0, val amount: Double = 0.0)

@Serializable
data class FinanceOverview(
    val partners: List<PartnerFinance> = emptyList(),
    val totals: FinanceTotals = FinanceTotals(),
    val business: BusinessProfit = BusinessProfit(),
    @SerialName("settle_invest") val settleInvest: List<SettleStep> = emptyList(),
    @SerialName("settle_balance") val settleBalance: List<SettleStep> = emptyList(),
    @SerialName("uncredited_offline") val uncreditedOffline: Uncredited = Uncredited(),
)

@Serializable
data class Movement(
    val id: Int = 0,
    val date: String = "",
    val partner: String = "",
    val direction: String = "credit",
    val kind: String = "normal",
    val amount: Double = 0.0,
    @SerialName("invest_adjust") val investAdjust: Double = 0.0,
    val source: String = "",
    val note: String? = null,
    @SerialName("event_id") val eventId: Int? = null,
    @SerialName("event_name") val eventName: String? = null,
)

@Serializable
data class MovementsResponse(
    val movements: List<Movement> = emptyList(),
    @SerialName("has_more") val hasMore: Boolean = false,
)

// ── Expenses ───────────────────────────────────────────────────────

@Serializable
data class Expense(
    val id: Int = 0,
    val date: String = "",
    val item: String = "",
    val amount: Double = 0.0,
    val discount: Double = 0.0,
    val net: Double = 0.0,
    val category: String = "",
    @SerialName("paid_to") val paidTo: String = "",
    @SerialName("paid_by") val paidBy: String? = null,
    @SerialName("paid_by_id") val paidById: Int = 0,
    val details: String = "",
    val settled: Double = 0.0,
    @SerialName("has_receipt") val hasReceipt: Boolean = false,
)

@Serializable
data class ExpenseSummary(
    val count: Int = 0,
    val gross: Double = 0.0,
    val discount: Double = 0.0,
    val net: Double = 0.0,
)

@Serializable
data class CategoryStat(val category: String = "", val count: Int = 0, val net: Double = 0.0)

@Serializable
data class ExpenseListResponse(
    val expenses: List<Expense> = emptyList(),
    @SerialName("has_more") val hasMore: Boolean = false,
    val range: DateRange = DateRange(),
    val summary: ExpenseSummary = ExpenseSummary(),
    @SerialName("by_category") val byCategory: List<CategoryStat> = emptyList(),
)

@Serializable
data class SimpleMessage(val message: String = "")

@Serializable
data class ProductsResponse(val products: List<Product> = emptyList(), val message: String = "")

@Serializable
data class EventsResponse(val events: List<Event> = emptyList(), val message: String = "")

@Serializable
data class SettingsResponse(val settings: Settings = Settings(), val message: String = "")
