# kotlinx.serialization keeps its serializers in companion objects and
# generated $$serializer classes; R8 cannot see they are used, so without
# these rules a release build parses every API response into nothing.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**

-keepclassmembers class com.madeforu.sales.data.** {
    *** Companion;
}
-keepclasseswithmembers class com.madeforu.sales.data.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class com.madeforu.sales.data.**$$serializer { *; }

# OkHttp ships optional Conscrypt/BouncyCastle hooks it only uses when the
# classes are present. R8 warns about them otherwise; they are not errors.
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Coil resolves its decoders reflectively through OkHttp's platform hooks.
-dontwarn coil.**
