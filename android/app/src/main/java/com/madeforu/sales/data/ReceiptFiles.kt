package com.madeforu.sales.data

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.provider.OpenableColumns
import androidx.core.content.FileProvider
import com.madeforu.sales.core.ApiResult
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import java.io.File

/**
 * Receipts on the phone: taking or picking one, making it small enough to
 * upload, and opening one the server has.
 *
 * The server accepts JPEG, PNG, WebP or PDF up to 5 MB (lib_expenses.php).
 * A phone photo is often bigger than that and far bigger than a receipt
 * needs, so photos are scaled to 1600 px on the long side and saved as
 * JPEG; a PDF goes as it is.
 */
object ReceiptFiles {

    private const val MAX_BYTES = 5 * 1024 * 1024
    private const val MAX_SIDE = 1600

    /** A fresh file for the camera to write a photo into, as a content:// Uri. */
    fun newPhotoUri(context: Context): Uri {
        val dir = File(context.cacheDir, "receipts").apply { mkdirs() }
        val file = File(dir, "photo-${System.currentTimeMillis()}.jpg")
        return FileProvider.getUriForFile(context, context.packageName + ".fileprovider", file)
    }

    /** What the person sees for a chosen file: its name, or "Photo". */
    fun displayName(context: Context, uri: Uri): String =
        context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
            ?.use { c -> if (c.moveToFirst()) c.getString(0) else null }
            ?: "Photo"

    /** The file, ready to upload: bytes, content type and a name. Throws with a readable message. */
    suspend fun forUpload(context: Context, uri: Uri): Triple<ByteArray, String, String> = withContext(Dispatchers.IO) {
        val resolver = context.contentResolver
        val type = resolver.getType(uri).orEmpty()
        if (type == "application/pdf") {
            val bytes = resolver.openInputStream(uri)?.use { it.readBytes() }
                ?: throw IllegalStateException("Could not read that file.")
            if (bytes.size > MAX_BYTES) throw IllegalStateException("That PDF is larger than 5 MB.")
            return@withContext Triple(bytes, "application/pdf", "receipt.pdf")
        }
        // Anything else is treated as a picture: read its size, then decode
        // it already scaled down, so a 50-megapixel photo never fills memory.
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
            throw IllegalStateException("That file is not a photo or a PDF.")
        }
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= MAX_SIDE) sample *= 2
        val decoded = resolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample })
        } ?: throw IllegalStateException("Could not read that photo.")
        val scale = MAX_SIDE.toFloat() / maxOf(decoded.width, decoded.height)
        val bitmap = if (scale < 1f) {
            Bitmap.createScaledBitmap(decoded, (decoded.width * scale).toInt(), (decoded.height * scale).toInt(), true)
        } else decoded
        val out = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.JPEG, 85, out)
        Triple(out.toByteArray(), "image/jpeg", "receipt.jpg")
    }

    /**
     * Fetch an expense's receipt and open it in whatever the phone views
     * photos or PDFs with. Null when it opened, or the reason it did not.
     */
    suspend fun open(context: Context, repository: Repository, expenseId: Int): String? {
        val (bytes, type) = when (val r = repository.receipt(expenseId)) {
            is ApiResult.Success -> r.value
            is ApiResult.Failure -> return r.message
        }
        val ext = when (type) {
            "application/pdf" -> "pdf"
            "image/png" -> "png"
            "image/webp" -> "webp"
            else -> "jpg"
        }
        val uri = withContext(Dispatchers.IO) {
            val dir = File(context.cacheDir, "receipts").apply { mkdirs() }
            val file = File(dir, "receipt-$expenseId.$ext")
            file.writeBytes(bytes)
            FileProvider.getUriForFile(context, context.packageName + ".fileprovider", file)
        }
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, type.ifBlank { "image/jpeg" })
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        return try {
            context.startActivity(Intent.createChooser(intent, "Open receipt").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            null
        } catch (e: ActivityNotFoundException) {
            "No app on this phone can open " + (if (ext == "pdf") "PDFs." else "photos.")
        }
    }

    /**
     * Save an expense, then its receipt: attach the newly chosen one, or
     * remove the old one if asked. One place for this, so adding and
     * editing behave the same. The expense is saved even when the receipt
     * fails, and the message says so.
     */
    suspend fun saveWithReceipt(
        context: Context,
        repository: Repository,
        id: Int?,
        form: com.madeforu.sales.ui.screens.ExpenseForm,
    ): ApiResult<String> {
        val saved = repository.saveExpense(
            id = id,
            date = form.date,
            item = form.item,
            amount = form.amount,
            discount = form.discount,
            paidBy = form.paidBy,
            category = form.category,
            paidTo = form.paidTo,
            details = form.details,
            split = form.split,
            lines = form.lines,
        )
        val value = when (saved) {
            is ApiResult.Success -> saved.value
            is ApiResult.Failure -> return saved
        }
        val receipt = form.receipt
        val note = when {
            receipt != null && value.id > 0 -> {
                val upload = try {
                    val (bytes, type, name) = forUpload(context, receipt)
                    repository.uploadReceipt(value.id, bytes, type, name)
                } catch (e: Exception) {
                    ApiResult.Failure("receipt", e.message ?: "Could not read the receipt.")
                }
                when (upload) {
                    is ApiResult.Success -> " Receipt attached."
                    is ApiResult.Failure -> " But the receipt was not attached: " + upload.message
                }
            }
            form.removeReceipt && value.id > 0 -> when (val r = repository.removeReceipt(value.id)) {
                is ApiResult.Success -> " Receipt removed."
                is ApiResult.Failure -> " But the receipt was not removed: " + r.message
            }
            else -> ""
        }
        return ApiResult.Success(value.message + note)
    }
}
