package com.madeforu.sales.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
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
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.ui.screens.BillScreen
import com.madeforu.sales.ui.screens.BillsScreen
import com.madeforu.sales.ui.screens.CatalogScreen
import com.madeforu.sales.ui.screens.EventsScreen
import com.madeforu.sales.ui.screens.ExpensesScreen
import com.madeforu.sales.ui.screens.HomeScreen
import com.madeforu.sales.ui.screens.LoginScreen
import com.madeforu.sales.ui.screens.MoneyScreen
import com.madeforu.sales.ui.screens.NewOrderScreen
import com.madeforu.sales.ui.screens.OrderDetailScreen
import com.madeforu.sales.ui.screens.OrdersScreen
import com.madeforu.sales.ui.screens.SettingsScreen
import com.madeforu.sales.ui.screens.StatsScreen
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

@Composable
fun AppRoot(signedIn: Boolean) {
    val navController = rememberNavController()
    val context = LocalContext.current
    val repository = remember { ServiceLocator.repository(context) }
    val snackbarHostState = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route

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
        snackbarHost = { SnackbarHost(snackbarHostState) },
        bottomBar = {
            AnimatedVisibility(visible = showBottomBar) {
                NavigationBar(tonalElevation = 3.dp) {
                    bottomDestinations.forEach { destination ->
                        val selected = currentRoute == destination.route
                        NavigationBarItem(
                            selected = selected,
                            onClick = {
                                if (!selected) {
                                    navController.navigate(destination.route) {
                                        // Keep one copy of each top-level
                                        // screen and its scroll position;
                                        // tapping between tabs should not
                                        // build a stack to back out of.
                                        popUpTo(Routes.HOME) { saveState = true }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
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
        Box(Modifier.fillMaxSize().padding(padding)) {
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
                // The bottom bar navigates to the bare route, which has to
                // resolve to the same screen as the filtered one above.
                composable(Routes.ORDERS) {
                    OrdersScreen(
                        repository = repository,
                        initialPayFilter = "all",
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
                        onOpenExpenses = { navController.navigate(Routes.EXPENSES) },
                        onOpenEvents = { navController.navigate(Routes.EVENTS) },
                        onOpenCatalog = { navController.navigate(Routes.CATALOG) },
                        onOpenSettings = { navController.navigate(Routes.SETTINGS) },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.EXPENSES) {
                    ExpensesScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
                    )
                }

                composable(Routes.EVENTS) {
                    EventsScreen(
                        repository = repository,
                        onBack = { navController.popBackStack() },
                        onSessionExpired = signOut,
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
