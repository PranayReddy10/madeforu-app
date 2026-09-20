package com.madeforu.sales.data

import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Prefs
import com.madeforu.sales.core.map
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * Typed wrapper over the API. Screens call these; nothing above this layer
 * knows an endpoint name or a query parameter.
 */
class Repository(private val api: ApiClient, private val prefs: Prefs) {

    // ── Session ────────────────────────────────────────────────────

    suspend fun login(phone: String, password: String, device: String): ApiResult<Admin> {
        val result = api.post(
            "auth.php", "login",
            ApiClient.body {
                put("phone", JsonPrimitive(phone))
                put("password", JsonPrimitive(password))
                put("device", JsonPrimitive(device))
            },
        )
        return when (result) {
            is ApiResult.Failure -> result
            is ApiResult.Success -> {
                val parsed: LoginResponse = api.decode(result.value)
                prefs.saveSession(parsed.token, parsed.admin.name, parsed.admin.phone)
                ApiResult.Success(parsed.admin)
            }
        }
    }

    /**
     * Ends the session locally whatever the server says. A token the app
     * has thrown away cannot be used from this phone, and refusing to sign
     * out because the network is down would be absurd.
     */
    suspend fun logout() {
        api.post("auth.php", "logout")
        prefs.clearSession()
    }

    suspend fun clearSessionLocally() = prefs.clearSession()

    /**
     * Ping, keeping the answer. The version and feature list are what
     * Settings shows so "I updated and nothing changed" has a factual
     * answer: the app, the server, or neither.
     */
    suspend fun ping(): ApiResult<ServerInfo> =
        api.get("auth.php", "ping").map { api.decode<ServerInfo>(it) }

    suspend fun changePassword(current: String, new: String): ApiResult<String> =
        api.post(
            "auth.php", "change_password",
            ApiClient.body {
                put("current_password", JsonPrimitive(current))
                put("new_password", JsonPrimitive(new))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    // ── Catalogue ──────────────────────────────────────────────────

    suspend fun bootstrap(): ApiResult<Bootstrap> =
        api.get("catalog.php", "bootstrap").map { api.decode<Bootstrap>(it) }

    suspend fun products(includeHidden: Boolean = false): ApiResult<List<Product>> =
        api.get("catalog.php", "products", mapOf("include_hidden" to includeHidden.toString()))
            .map { api.decode<ProductsResponse>(it).products }

    suspend fun addProduct(name: String, price: Double, unitCost: Double): ApiResult<List<Product>> =
        api.post(
            "catalog.php", "add_product",
            ApiClient.body {
                put("name", JsonPrimitive(name))
                put("price", JsonPrimitive(price))
                put("unit_cost", JsonPrimitive(unitCost))
            },
        ).map { api.decode<ProductsResponse>(it).products }

    /** What this product has sold for over time, newest first. */
    suspend fun priceHistory(item: String): ApiResult<PriceHistory> =
        api.get("catalog.php", "price_history", mapOf("item" to item))
            .map { api.decode<PriceHistory>(it) }

    /**
     * Update a product, keeping the server's sentence about it.
     *
     * The message names what a price change does and does not touch
     * ("applies to new sales only; the 62 orders already sold keep the
     * price they were sold at"), which is the reassurance the partner
     * changing the price actually needs. Dropping it for the product
     * list alone would throw away the answer.
     */
    suspend fun updateProductDetailed(
        id: Int,
        price: Double? = null,
        unitCost: Double? = null,
        isActive: Boolean? = null,
        imageUrl: String? = null,
        productUrl: String? = null,
    ): ApiResult<ProductsResponse> =
        api.post(
            "catalog.php", "update_product",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                price?.let { put("price", JsonPrimitive(it)) }
                unitCost?.let { put("unit_cost", JsonPrimitive(it)) }
                isActive?.let { put("is_active", JsonPrimitive(it)) }
                imageUrl?.let { put("image_url", JsonPrimitive(it)) }
                productUrl?.let { put("product_url", JsonPrimitive(it)) }
            },
        ).map { api.decode<ProductsResponse>(it) }

    suspend fun updateProduct(
        id: Int,
        price: Double? = null,
        unitCost: Double? = null,
        isActive: Boolean? = null,
        imageUrl: String? = null,
        productUrl: String? = null,
    ): ApiResult<List<Product>> =
        api.post(
            "catalog.php", "update_product",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                // Only fields that are passed are touched: the server
                // leaves out anything absent, so editing a price cannot
                // blank a photo URL by omission.
                price?.let { put("price", JsonPrimitive(it)) }
                unitCost?.let { put("unit_cost", JsonPrimitive(it)) }
                isActive?.let { put("is_active", JsonPrimitive(it)) }
                imageUrl?.let { put("image_url", JsonPrimitive(it)) }
                productUrl?.let { put("product_url", JsonPrimitive(it)) }
            },
        ).map { api.decode<ProductsResponse>(it).products }

    suspend fun events(activeOnly: Boolean = false): ApiResult<List<Event>> =
        api.get("catalog.php", "events", mapOf("active_only" to activeOnly.toString()))
            .map { api.decode<EventsResponse>(it).events }

    suspend fun addEvent(
        name: String,
        isPaid: Boolean,
        entryCost: Double,
        startDate: String,
        endDate: String,
        notes: String,
    ): ApiResult<List<Event>> =
        api.post(
            "catalog.php", "add_event",
            ApiClient.body {
                put("name", JsonPrimitive(name))
                put("is_paid", JsonPrimitive(isPaid))
                put("entry_cost", JsonPrimitive(entryCost))
                put("start_date", JsonPrimitive(startDate))
                put("end_date", JsonPrimitive(endDate))
                put("notes", JsonPrimitive(notes))
            },
        ).map { api.decode<EventsResponse>(it).events }

    suspend fun setEventActive(id: Int, active: Boolean): ApiResult<List<Event>> =
        api.post(
            "catalog.php", "set_event_active",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                put("is_active", JsonPrimitive(active))
            },
        ).map { api.decode<EventsResponse>(it).events }

    suspend fun saveSettings(values: Map<String, String>): ApiResult<Settings> =
        api.post(
            "catalog.php", "save_settings",
            ApiClient.body { values.forEach { (k, v) -> put(k, JsonPrimitive(v)) } },
        ).map { api.decode<SettingsResponse>(it).settings }

    // ── Orders ─────────────────────────────────────────────────────

    suspend fun orders(
        query: String = "",
        payFilter: String = "all",
        statusFilter: String = "all",
        event: String = "all",
        from: String = "",
        to: String = "",
        limit: Int = 40,
        offset: Int = 0,
    ): ApiResult<OrderListResponse> =
        api.get(
            "orders.php", "list",
            mapOf(
                "q" to query,
                "pay" to payFilter,
                "status" to statusFilter,
                "event" to event,
                "from" to from,
                "to" to to,
                "limit" to limit.toString(),
                "offset" to offset.toString(),
            ),
        ).map { api.decode<OrderListResponse>(it) }

    suspend fun order(id: Int): ApiResult<Order> =
        api.get("orders.php", "get", mapOf("id" to id.toString()))
            .map { api.decode<OrderResponse>(it).order }

    /**
     * Create an order. Name and phone are both optional — leaving them out
     * is what makes this a walk-in sale, which is the fast path at a stall.
     */
    suspend fun createOrder(draft: OrderDraft): ApiResult<OrderResponse> =
        api.post("orders.php", "create", draft.toBody()).map { api.decode<OrderResponse>(it) }

    suspend fun updateOrder(id: Int, draft: OrderDraft): ApiResult<OrderResponse> =
        api.post(
            "orders.php", "update",
            JsonObject(draft.toBody().toMutableMap().apply { put("id", JsonPrimitive(id)) }),
        ).map { api.decode<OrderResponse>(it) }

    suspend fun addPayment(id: Int, amount: Double, mode: String, note: String): ApiResult<OrderResponse> =
        api.post(
            "orders.php", "add_payment",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                put("amount", JsonPrimitive(amount))
                put("payment_mode", JsonPrimitive(mode))
                put("note", JsonPrimitive(note))
            },
        ).map { api.decode<OrderResponse>(it) }

    suspend fun deletePayment(orderId: Int, paymentId: Int): ApiResult<OrderResponse> =
        api.post(
            "orders.php", "delete_payment",
            ApiClient.body {
                put("id", JsonPrimitive(orderId))
                put("payment_id", JsonPrimitive(paymentId))
            },
        ).map { api.decode<OrderResponse>(it) }

    suspend fun toggle(id: Int, field: String): ApiResult<OrderResponse> =
        api.post(
            "orders.php", "toggle",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                put("field", JsonPrimitive(field))
            },
        ).map { api.decode<OrderResponse>(it) }

    suspend fun dispatch(id: Int, awb: String, date: String): ApiResult<OrderResponse> =
        api.post(
            "orders.php", "dispatch",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                // An empty AWB with is_online false is how the server is
                // told to clear a dispatch entirely.
                put("is_online", JsonPrimitive(awb.isNotBlank()))
                put("awb", JsonPrimitive(awb))
                put("dispatch_date", JsonPrimitive(date))
            },
        ).map { api.decode<OrderResponse>(it) }

    suspend fun deleteOrder(id: Int): ApiResult<String> =
        api.post("orders.php", "delete", ApiClient.body { put("id", JsonPrimitive(id)) })
            .map { api.decode<SimpleMessage>(it).message }

    // ── Bills ──────────────────────────────────────────────────────

    suspend fun bill(orderId: Int): ApiResult<Bill> =
        api.get("bills.php", "get", mapOf("order_id" to orderId.toString()))
            .map { api.decode<BillResponse>(it).bill }

    /** Re-snapshot a bill after the order changed; bumps its revision. */
    suspend fun reissueBill(orderId: Int): ApiResult<Bill> =
        api.post(
            "bills.php", "issue",
            ApiClient.body {
                put("order_id", JsonPrimitive(orderId))
                put("refresh", JsonPrimitive(true))
            },
        ).map { api.decode<BillResponse>(it).bill }

    suspend fun bills(limit: Int = 50, offset: Int = 0): ApiResult<BillListResponse> =
        api.get("bills.php", "list", mapOf("limit" to limit.toString(), "offset" to offset.toString()))
            .map { api.decode<BillListResponse>(it) }

    suspend fun billUrl(orderId: Int, thermal: Boolean = false): String = api.billUrl(orderId, thermal)

    // ── Statistics ─────────────────────────────────────────────────

    suspend fun dashboard(from: String, to: String, event: String = "all"): ApiResult<Dashboard> =
        api.get("stats.php", "dashboard", mapOf("from" to from, "to" to to, "event" to event))
            .map { api.decode<Dashboard>(it) }

    suspend fun series(from: String, to: String, bucket: String, event: String = "all"): ApiResult<SeriesResponse> =
        api.get(
            "stats.php", "series",
            mapOf("from" to from, "to" to to, "bucket" to bucket, "event" to event),
        ).map { api.decode<SeriesResponse>(it) }

    suspend fun breakdown(from: String, to: String, event: String = "all"): ApiResult<Breakdown> =
        api.get("stats.php", "breakdown", mapOf("from" to from, "to" to to, "event" to event))
            .map { api.decode<Breakdown>(it) }

    // ── Finance ────────────────────────────────────────────────────

    suspend fun financeOverview(): ApiResult<FinanceOverview> =
        api.get("finance.php", "overview").map { api.decode<FinanceOverview>(it) }

    suspend fun movements(partnerId: Int = 0, from: String = "", to: String = ""): ApiResult<MovementsResponse> =
        api.get(
            "finance.php", "movements",
            mapOf("partner_id" to partnerId.toString(), "from" to from, "to" to to),
        ).map { api.decode<MovementsResponse>(it) }

    suspend fun addMovement(
        partnerId: Int,
        direction: String,
        amount: Double,
        date: String,
        source: String,
        note: String,
        accountId: Int? = null,
        channelId: Int? = null,
        // 'payout' marks a marketplace settling up for orders already on
        // the books: it moves an account balance without being counted as
        // revenue a second time.
        kind: String = "normal",
    ): ApiResult<String> =
        api.post(
            "finance.php", "add_movement",
            ApiClient.body {
                put("partner_id", JsonPrimitive(partnerId))
                put("direction", JsonPrimitive(direction))
                put("amount", JsonPrimitive(amount))
                put("mov_date", JsonPrimitive(date))
                put("source", JsonPrimitive(source))
                put("note", JsonPrimitive(note))
                put("kind", JsonPrimitive(kind))
                if (accountId != null) put("account_id", JsonPrimitive(accountId))
                if (channelId != null) put("channel_id", JsonPrimitive(channelId))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    /** Correct a movement that was entered wrong. */
    suspend fun updateMovement(
        id: Int,
        partnerId: Int,
        direction: String,
        amount: Double,
        date: String,
        source: String,
        note: String,
        accountId: Int? = null,
        channelId: Int? = null,
        kind: String = "normal",
    ): ApiResult<String> =
        api.post(
            "finance.php", "update_movement",
            ApiClient.body {
                put("id", JsonPrimitive(id))
                put("partner_id", JsonPrimitive(partnerId))
                put("direction", JsonPrimitive(direction))
                put("amount", JsonPrimitive(amount))
                put("mov_date", JsonPrimitive(date))
                put("source", JsonPrimitive(source))
                put("note", JsonPrimitive(note))
                put("kind", JsonPrimitive(kind))
                if (accountId != null) put("account_id", JsonPrimitive(accountId))
                if (channelId != null) put("channel_id", JsonPrimitive(channelId))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    /**
     * Channels with their price overrides, the account list, and what
     * each marketplace still owes — in one call.
     *
     * Sent together rather than fetched per channel because the sale
     * screen prices lines as the basket changes: a round trip on every
     * change of the picker would show the wrong total until it landed.
     */
    suspend fun trade(): ApiResult<TradeResponse> =
        api.get("trade.php", "list").map { api.decode<TradeResponse>(it) }

    suspend fun setChannelPrice(channelId: Int, item: String, price: Double?): ApiResult<TradeResponse> =
        api.post(
            "trade.php", "set_channel_price",
            ApiClient.body {
                put("channel_id", JsonPrimitive(channelId))
                put("item", JsonPrimitive(item))
                // Absent clears the override, which is not the same as
                // zero: zero is a free item.
                if (price != null) put("price", JsonPrimitive(price))
            },
        ).map { api.decode<TradeResponse>(it) }

    /** Raw material bought against raw material used. */
    suspend fun materialAudit(): ApiResult<MaterialAuditResponse> =
        api.get("trade.php", "material_audit").map { api.decode<MaterialAuditResponse>(it) }

    /**
     * Remove a movement.
     *
     * The server handles the two cases that matter: a settlement loses
     * both its sides, and a credit raised from offline sales releases the
     * orders it stamped so they can be credited again.
     */
    suspend fun deleteMovement(id: Int): ApiResult<String> =
        api.post(
            "finance.php", "delete_movement",
            ApiClient.body { put("id", JsonPrimitive(id)) },
        ).map { api.decode<SimpleMessage>(it).message }

    suspend fun creditOffline(partnerId: Int, from: String, to: String): ApiResult<String> =
        api.post(
            "finance.php", "credit_offline",
            ApiClient.body {
                put("partner_id", JsonPrimitive(partnerId))
                put("from", JsonPrimitive(from))
                put("to", JsonPrimitive(to))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    suspend fun creditEvent(eventId: Int, partnerId: Int): ApiResult<String> =
        api.post(
            "finance.php", "credit_event",
            ApiClient.body {
                put("event_id", JsonPrimitive(eventId))
                put("partner_id", JsonPrimitive(partnerId))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    /** Equal split across active partners, exactly as investment.php does it. */
    suspend fun distributeProfit(amount: Double): ApiResult<String> =
        api.post(
            "finance.php", "distribute_profit",
            ApiClient.body { put("amount", JsonPrimitive(amount)) },
        ).map { api.decode<SimpleMessage>(it).message }

    suspend fun settle(fromId: Int, toId: Int, amount: Double, note: String): ApiResult<String> =
        api.post(
            "finance.php", "settle",
            ApiClient.body {
                put("from_partner_id", JsonPrimitive(fromId))
                put("to_partner_id", JsonPrimitive(toId))
                put("amount", JsonPrimitive(amount))
                put("note", JsonPrimitive(note))
            },
        ).map { api.decode<SimpleMessage>(it).message }

    // ── Expenses ───────────────────────────────────────────────────

    suspend fun expenses(from: String, to: String, category: String = "all", query: String = ""): ApiResult<ExpenseListResponse> =
        api.get(
            "expenses.php", "list",
            mapOf("from" to from, "to" to to, "category" to category, "q" to query),
        ).map { api.decode<ExpenseListResponse>(it) }

    suspend fun expense(id: Int): ApiResult<Expense> =
        api.get("expenses.php", "get", mapOf("id" to id.toString()))
            .map { api.decode<ExpenseDetailResponse>(it).expense }

    /**
     * @param split who actually put the money in. Empty means the whole net
     * amount against [paidBy], which is the common case. When several
     * partners chipped in, each row is recorded separately — that table is
     * what counts towards a partner's investment.
     */
    suspend fun saveExpense(
        id: Int?,
        date: String,
        item: String,
        amount: Double,
        discount: Double,
        paidBy: Int,
        category: String,
        paidTo: String,
        details: String,
        split: List<Pair<Int, Double>> = emptyList(),
    ): ApiResult<String> =
        api.post(
            "expenses.php", if (id == null) "create" else "update",
            ApiClient.body {
                if (id != null) put("id", JsonPrimitive(id))
                put("exp_date", JsonPrimitive(date))
                put("item", JsonPrimitive(item))
                put("amount", JsonPrimitive(amount))
                put("discount", JsonPrimitive(discount))
                put("paid_by", JsonPrimitive(paidBy))
                put("category", JsonPrimitive(category))
                put("paid_to", JsonPrimitive(paidTo))
                put("details", JsonPrimitive(details))
                if (split.isNotEmpty()) {
                    put(
                        "payments",
                        kotlinx.serialization.json.JsonArray(
                            split.map { (partnerId, amt) ->
                                kotlinx.serialization.json.JsonObject(
                                    mapOf(
                                        "partner_id" to JsonPrimitive(partnerId),
                                        "amount" to JsonPrimitive(amt),
                                    ),
                                )
                            },
                        ),
                    )
                }
            },
        ).map { api.decode<SimpleMessage>(it).message }

    suspend fun release(): ApiResult<Release> =
        api.get("auth.php", "app_version").map { api.decode<ReleaseResponse>(it).release }

    suspend fun deleteExpense(id: Int): ApiResult<String> =
        api.post("expenses.php", "delete", ApiClient.body { put("id", JsonPrimitive(id)) })
            .map { api.decode<SimpleMessage>(it).message }
}

/** One line on an order being composed. */
data class DraftLine(val item: String, val price: Double, val quantity: Int)

/**
 * An order as the app builds it, before the server assigns a number and
 * recomputes the money. Prices here are only for the running total shown
 * on screen; the server re-reads every price from the catalogue, so a
 * stale price in the app cannot change what a customer is charged.
 */
data class OrderDraft(
    val name: String = "",
    val phone: String = "",
    val notes: String = "",
    val eventId: Int? = null,
    // Where the sale came from. Independent of the event, and it decides
    // what the lines are priced at.
    val channelId: Int? = null,
    val lines: List<DraftLine> = emptyList(),
    val extraCharge: Double = 0.0,
    val extraChargeReason: String = "",
    val discount: Double = 0.0,
    val discountReason: String = "",
    val paidAmount: Double = 0.0,
    val paymentMode: String = "cash",
    val isReady: Boolean = false,
    val isDelivered: Boolean = false,
    val awb: String = "",
    val dispatchDate: String = "",
) {
    val subtotal: Double get() = lines.sumOf { it.price * it.quantity }

    /** What the server will charge: subtotal + extra, less the discount, floored at zero. */
    val total: Double get() = (subtotal + extraCharge - discount).coerceAtLeast(0.0)

    val balance: Double get() = (total - paidAmount).coerceAtLeast(0.0)

    val isWalkIn: Boolean get() = name.isBlank() && phone.isBlank()

    fun toBody(): JsonObject {
        val map = LinkedHashMap<String, JsonElement>()
        map["name"] = JsonPrimitive(name)
        map["phone"] = JsonPrimitive(phone)
        map["notes"] = JsonPrimitive(notes)
        if (eventId != null) map["event_id"] = JsonPrimitive(eventId)
        if (channelId != null) map["channel_id"] = JsonPrimitive(channelId)
        map["items"] = kotlinx.serialization.json.JsonArray(
            lines.map { line ->
                JsonObject(
                    mapOf(
                        "item" to JsonPrimitive(line.item),
                        "quantity" to JsonPrimitive(line.quantity),
                    ),
                )
            },
        )
        map["extra_charge"] = JsonPrimitive(extraCharge)
        map["extra_charge_reason"] = JsonPrimitive(extraChargeReason)
        map["discount"] = JsonPrimitive(discount)
        map["discount_reason"] = JsonPrimitive(discountReason)
        map["paid_amount"] = JsonPrimitive(paidAmount)
        map["payment_mode"] = JsonPrimitive(paymentMode)
        map["is_ready"] = JsonPrimitive(isReady)
        map["is_delivered"] = JsonPrimitive(isDelivered)
        if (awb.isNotBlank()) {
            map["is_online"] = JsonPrimitive(true)
            map["awb"] = JsonPrimitive(awb)
            map["dispatch_date"] = JsonPrimitive(dispatchDate)
        }
        return JsonObject(map)
    }
}
