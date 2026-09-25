package com.madeforu.sales.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddCircle
import androidx.compose.material.icons.filled.BarChart
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material.icons.filled.Savings
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.notify.ActivitySync
import com.madeforu.sales.notify.PushSetup
import com.madeforu.sales.ui.screens.BillScreen
import com.madeforu.sales.ui.screens.BillsScreen
import com.madeforu.sales.ui.screens.CatalogScreen
import com.madeforu.sales.ui.screens.EventsScreen
import com.madeforu.sales.ui.screens.ExpenseDetailScreen
import com.madeforu.sales.ui.screens.ExpensesScreen
import com.madeforu.sales.ui.screens.HomeScreen
import com.madeforu.sales.ui.screens.LoginScreen
import com.madeforu.sales.ui.screens.MoneyScreen
import com.madeforu.sales.ui.screens.MovementsScreen
import com.madeforu.sales.ui.screens.NewOrderScreen
import com.madeforu.sales.ui.screens.OrderDetailScreen
import com.madeforu.sales.ui.screens.OrdersScreen
import com.madeforu.sales.ui.screens.SettingsScreen
import com.madeforu.sales.ui.screens.StatsScreen
import com.madeforu.sales.ui.screens.WholesaleBuyerScreen
import com.madeforu.sales.ui.screens.WholesaleScreen
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

private data class BottomDestination(
    val route: String,
    val label: String,
    val icon: ImageVector,
)

private val bottomDestinations = listOf(
    BottomDestination(Routes.HOME, "Home", Icons.Filled.Home),
    BottomDestination(Routes.ORDERS, "Orders", Icons.Filled.ReceiptLong),
    BottomDestination(Routes.NEW_ORDER, "New sale", Icons.Filled.AddCircle),
    BottomDestination(Routes.STATS, "Stats", Icons.Filled.BarChart),
    BottomDestination(Routes.MONEY, "Money", Icons.Filled.Savings),
)

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun AppRoot(
    signedIn: Boolean,
    openOrderId: Int? = null,
    onOrderOpened: () -> Unit = {},
) {
    val navController = rememberNavController()
    val context = LocalContext.current
    val repository = remember { ServiceLocator.repository(context) }
    val snackbarHostState = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    val backStackEntry by navController.currentBackStackEntryAsState()
    // Strip the argument pattern: a route registered as "orders?pay={pay}"
    // reads back with the pattern attached, and comparing that to "orders"
    // would leave the tab unhighlighted and re-navigate on every tap.
    val currentRoute = backStackEntry?.destination?.route?.substringBefore('?')

    // The bar is for the five top-level places. On a detail screen it would
    // just be a second way to lose your place.
    val showBottomBar = currentRoute in Routes.bottomBar

    /** Every screen reports a result this way, so messages look the same everywhere. */
    val notify: (String) -> Unit = { message ->
        scope.launch { snackbarHostState.showSnackbar(message) }
    }

    /** Called when the server says the session is gone. */
    val signOut: () -> Unit = {
        scope.launch {
            repository.clearSessionLocally()
            navController.navigate(Routes.LOGIN) {
                popUpTo(navController.graph.id) { inclusive = true }
            }
        }
    }

    Scaffold(
        modifier = Modifier.fillMaxSize(),
        // Insets are the inner screens' job: each one has its own
        // Scaffold or TopAppBar, which already handles the status bar.
        // Letting this Scaffold handle them too applied the same padding
        // twice and left a blank strip above every app bar.
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
        snackbarHost = { SnackbarHost(snackbarHostState) },
        bottomBar = {
            // The tabs step aside for the keyboard. They have to: a screen
            // inside this Scaffold that lifts itself above the keyboard is
            // measured within the area left over once this bar is taken
            // out, so it stops a bar's height short of the keyboard and
            // leaves a blank band under it. Read here rather than in the
            // body of AppRoot so the keyboard animation only recomposes
            // this slot, not the whole app.
            val keyboardOpen = WindowInsets.ime.getBottom(LocalDensity.current) > 0
            AnimatedVisibility(visible = showBottomBar && !keyboardOpen) {
                NavigationBar(tonalElevation = 3.dp) {
                    bottomDestinations.forEach { destination ->
                        val selected = currentRoute == destination.route
                        // New sale is an action, not a place: it always
                        // starts empty, and tapping it while already there
                        // is how you clear the form for the next customer.
                        val isNewSale = destination.route == Routes.NEW_ORDER
                        NavigationBarItem(
                            selected = selected,
                            onClick = {
                                navController.navigate(destination.route) {
                                    // Pop back to the graph's real start
                                    // destination, looked up rather than
                                    // assumed: the start is HOME when
                                    // already signed in and LOGIN when not,
                                    // and popUpTo against the wrong one
                                    // silently does nothing.
                                    popUpTo(navController.graph.findStartDestination().id) {
                                        inclusive = false
                                    }
                                    // No saveState/restoreState. Restoring a
                                    // tab's saved entry is what made tabs
                                    // stop responding: once an entry has
                                    // been consumed — saving an order pops
                                    // one — asking to restore it is a no-op
                                    // and the tap appears to do nothing.
                                    // Every screen reloads on entry anyway,
                                    // so the only thing given up is a
                                    // remembered scroll position.
                                    launchSingleTop = !isNewSale
                                }
                            },
                            icon = { Icon(destination.icon, contentDescription = destination.label) },
                            label = { Text(destination.label) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = MaterialTheme.colorScheme.onSecondaryContainer,
                                selectedTextColor = MaterialTheme.colorScheme.onSurface,
                                indicatorColor = MaterialTheme.colorScheme.secondaryContainer,
                            ),
                        )
                    }
                }
            }
        },
    ) { padding ->
        // Only the bottom: that is the space the navigation bar occupies,
        // and it is this Scaffold that draws it.
        Box(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding())) {
            NavHost(
                navController = navController,
                startDestination = if (signedIn) Routes.HOME else Routes.LOGIN,
            ) {
                composable(Routes.LOGIN) {
                    LoginScreen(
                        repository = repository,
                        onSignedIn = {
                            navController.navigate(Routes.HOME) {
                                popUpTo(Routes.LOGIN) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Routes.HOME) {
                    HomeScreen(
                        repository = repository,
                        onOpenOrders = { filter ->
                            navController.navigate("${Routes.ORDERS}?pay=$filter")
                        },
                        onNewOrder = { navController.navigate(Routes.NEW_ORDER) },
                        onOpenSettings = { navController.navigate(Routes.SETTINGS) },
                        onOpenBills = { navController.navigate(Routes.BILLS) },
                        onOpenExpenses = { navController.navigate(Routes.EXPENSES) },
                        onOpenWholesale = { navController.navigate(Routes.WHOLESALE) },
                        onOpenEvents = { navController.navigate(Routes.EVENTS) },
                        onOpenMovements = { navController.navigate(Routes.MOVEMENTS) },
                        onOpenCatalog = { navController.navigate(Routes.CATALOG) },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = "${Routes.ORDERS}?pay={pay}",
                    arguments = listOf(
                        navArgument("pay") { type = NavType.StringType; defaultValue = "all" },
                    ),
                ) { entry ->
                    OrdersScreen(
                        repository = repository,
                        initialPayFilter = entry.arguments?.getString("pay") ?: "all",
                        onOpenOrder = { id -> navController.navigate(Routes.orderDetail(id)) },
                        onNewOrder = { navController.navigate(Routes.NEW_ORDER) },
                        onSessionExpired = signOut,
                    )
                }
                composable(Routes.NEW_ORDER) {
                    NewOrderScreen(
                        repository = repository,
                        editOrderId = null,
                        onSaved = { orderId, message ->
                            notify(message)
                            navController.navigate(Routes.orderDetail(orderId)) {
                                popUpTo(Routes.NEW_ORDER) { inclusive = true }
                            }
                        },
                        onCancel = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.EDIT_ORDER,
                    arguments = listOf(navArgument("id") { type = NavType.IntType }),
                ) { entry ->
                    val id = entry.arguments?.getInt("id") ?: 0
                    NewOrderScreen(
                        repository = repository,
                        editOrderId = id,
                        onSaved = { orderId, message ->
                            notify(message)
                            navController.popBackStack()
                        },
                        onCancel = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.ORDER_DETAIL,
                    arguments = listOf(navArgument("id") { type = NavType.IntType }),
                ) { entry ->
                    val id = entry.arguments?.getInt("id") ?: 0
                    OrderDetailScreen(
                        repository = repository,
                        orderId = id,
                        onBack = { navController.popBackStack() },
                        onEdit = { navController.navigate(Routes.editOrder(id)) },
                        onOpenBill = { navController.navigate(Routes.bill(id)) },
                        onDeleted = { message ->
                            notify(message)
                            navController.popBackStack()
                        },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.BILL,
                    arguments = listOf(navArgument("id") { type = NavType.IntType }),
                ) { entry ->
                    BillScreen(
                        repository = repository,
                        orderId = entry.arguments?.getInt("id") ?: 0,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.BILLS) {
                    BillsScreen(
                        repository = repository,
                        onOpenBill = { orderId -> navController.navigate(Routes.bill(orderId)) },
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.STATS) {
                    StatsScreen(repository = repository, onSessionExpired = signOut)
                }

                composable(Routes.MONEY) {
                    MoneyScreen(
                        repository = repository,
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.MOVEMENTS) {
                    MovementsScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.EXPENSES) {
                    ExpensesScreen(
                        repository = repository,
                        onOpenExpense = { id -> navController.navigate(Routes.expenseDetail(id)) },
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.EXPENSE_DETAIL,
                    arguments = listOf(navArgument("id") { type = NavType.IntType }),
                ) { entry ->
                    ExpenseDetailScreen(
                        repository = repository,
                        expenseId = entry.arguments?.getInt("id") ?: 0,
                        onBack = { navController.popBackStack() },
                        onChanged = { message ->
                            notify(message)
                            // A delete leaves nothing to show; an edit
                            // reloads in place. Either way the list behind
                            // is stale, so step back to it.
                            navController.popBackStack()
                        },
                        onSessionExpired = signOut,
                    )
                }

                // The wholesale notebook. Deliberately not on the bottom
                // bar: it is not part of taking a sale, and putting it
                // there would suggest it is.
                composable(Routes.WHOLESALE) {
                    WholesaleScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onOpenBuyer = { id -> navController.navigate(Routes.wholesaleBuyer(id)) },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.WHOLESALE_BUYER,
                    arguments = listOf(navArgument("id") { type = NavType.IntType }),
                ) { entry ->
                    WholesaleBuyerScreen(
                        buyerId = entry.arguments?.getInt("id") ?: 0,
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.EVENTS) {
                    EventsScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onOpenEvent = { event -> navController.navigate(Routes.eventOrders(event.id, event.name)) },
                        onSessionExpired = signOut,
                    )
                }

                composable(
                    route = Routes.EVENT_ORDERS,
                    arguments = listOf(
                        navArgument("id") { type = NavType.IntType },
                        navArgument("name") { type = NavType.StringType; defaultValue = "" },
                    ),
                ) { entry ->
                    OrdersScreen(
                        repository = repository,
                        initialPayFilter = "all",
                        onOpenOrder = { id -> navController.navigate(Routes.orderDetail(id)) },
                        onNewOrder = { navController.navigate(Routes.NEW_ORDER) },
                        onSessionExpired = signOut,
                        eventId = (entry.arguments?.getInt("id") ?: 0).toString(),
                        title = entry.arguments?.getString("name")?.takeIf { it.isNotBlank() } ?: "Event orders",
                        onBack = { navController.popBackStack() },
                    )
                }

                composable(Routes.CATALOG) {
                    CatalogScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.SETTINGS) {
                    SettingsScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSignedOut = {
                            navController.navigate(Routes.LOGIN) {
                                popUpTo(navController.graph.id) { inclusive = true }
                            }
                        },
                    )
                }
            }
        }
    }

    // ── Notifications ────────────────────────────────────────────
    //
    // A tapped notification about an order opens that order.
    LaunchedEffect(openOrderId, currentRoute) {
        val id = openOrderId ?: return@LaunchedEffect
        if (currentRoute == null || currentRoute == Routes.LOGIN) return@LaunchedEffect
        navController.navigate(Routes.orderDetail(id))
        onOrderOpened()
    }

    // Android 13 and later ask before the app may notify. Asked once
    // someone is signed in, which is when there is something to hear about.
    //
    // Registering for real-time push happens at the same moment, and again
    // once permission is granted; until it succeeds, the checks below fill in.
    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { scope.launch { PushSetup.setup(context) } }
    LaunchedEffect(currentRoute == Routes.LOGIN) {
        if (currentRoute == null || currentRoute == Routes.LOGIN) return@LaunchedEffect
        PushSetup.setup(context)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            permissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    // While the app is on screen it checks every 30 seconds itself, which
    // is far sooner than the 15-minute background job. What it finds is
    // shown here as a message, not a system notification: the person is
    // already looking at the app. The shared cursor means the background
    // job will not announce the same things again later.
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                val items = ActivitySync.check(context)
                // With push active, the pushed notification already said it.
                if (items.isNotEmpty() && !PushSetup.isActive(context) &&
                    ServiceLocator.prefs(context).notificationsOn.first()
                ) {
                    notify(
                        if (items.size == 1) items[0].title + " · " + items[0].body.lineSequence().first()
                        else "${items.size} updates · latest: " + items.last().title,
                    )
                }
                delay(30_000)
            }
        }
    }

    // A session that expired while the app was in the background should
    // land on the login screen, not on a screen full of errors.
    LaunchedEffect(signedIn) {
        if (!signedIn && currentRoute != null && currentRoute != Routes.LOGIN) {
            navController.navigate(Routes.LOGIN) {
                popUpTo(navController.graph.id) { inclusive = true }
            }
        }
    }
}
