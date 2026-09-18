package com.madeforu.sales.core

/**
 * The result of one API call.
 *
 * Failure carries a `code` as well as a message so the app can act on
 * specific cases — `bad_token` signs the user out, everything else just
 * shows the server's message, which is already written for a partner to
 * read rather than for a developer to debug.
 */
sealed interface ApiResult<out T> {
    data class Success<T>(val value: T) : ApiResult<T>
    data class Failure(val code: String, val message: String) : ApiResult<Nothing>

    val successOrNull: T? get() = (this as? Success)?.value

    companion object {
        const val CODE_NETWORK = "network"
        const val CODE_BAD_TOKEN = "bad_token"
        const val CODE_EXPIRED = "expired_token"
        const val CODE_NO_TOKEN = "no_token"
    }
}

/** True when the session is gone and the app should return to the login screen. */
fun ApiResult.Failure.isAuthFailure(): Boolean =
    code == ApiResult.CODE_BAD_TOKEN ||
        code == ApiResult.CODE_EXPIRED ||
        code == ApiResult.CODE_NO_TOKEN

inline fun <T, R> ApiResult<T>.map(transform: (T) -> R): ApiResult<R> = when (this) {
    is ApiResult.Success -> ApiResult.Success(transform(value))
    is ApiResult.Failure -> this
}
