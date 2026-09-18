package com.madeforu.sales.ui

/**
 * Every destination in the app, in one place.
 *
 * Routes are plain strings rather than typed objects: the navigation
 * graph here is small and flat, and a string route is readable in a stack
 * trace without a decoder.
 */
object Routes {
    const val LOGIN = "login"
    const val HOME = "home"
    const val ORDERS = "orders"
    const val NEW_ORDER = "new_order"
    const val STATS = "stats"
    const val MONEY = "money"

    const val ORDER_DETAIL = "order/{id}"
    fun orderDetail(id: Int) = "order/$id"

    const val EDIT_ORDER = "order/{id}/edit"
    fun editOrder(id: Int) = "order/$id/edit"

    const val BILL = "bill/{id}"
    fun bill(id: Int) = "bill/$id"

    const val BILLS = "bills"
    const val EXPENSES = "expenses"

    const val EXPENSE_DETAIL = "expense/{id}"
    fun expenseDetail(id: Int) = "expense/$id"
    const val CATALOG = "catalog"
    const val EVENTS = "events"
    const val SETTINGS = "settings"

    /** The five destinations reachable from the bottom bar. */
    val bottomBar = listOf(HOME, ORDERS, NEW_ORDER, STATS, MONEY)
}
