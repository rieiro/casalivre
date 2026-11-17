package com.casalivre.app.data.api;

import android.content.Context;
import com.casalivre.app.data.SessionManager;
import com.squareup.moshi.JsonAdapter;
import com.squareup.moshi.Moshi;
import java.util.concurrent.TimeUnit;
import okhttp3.OkHttpClient;
import okhttp3.logging.HttpLoggingInterceptor;
import org.json.JSONObject;
import org.jsoup.Jsoup;
import org.jsoup.nodes.Document;
import org.jsoup.nodes.Element;

public final class ApiClient {
    public static final String BASE_URL = "http://192.168.0.115/";
    private static OkHttpClient client;
    private static Moshi moshi;

    public static synchronized void init(Context ctx) {
        if (client != null) return;
        SessionManager session = SessionManager.get(ctx);
        HttpLoggingInterceptor log = new HttpLoggingInterceptor();
        log.setLevel(HttpLoggingInterceptor.Level.BASIC);

        client = new OkHttpClient.Builder()
                .cookieJar(new WebViewCookieJar())
                .addInterceptor(new CsrfInterceptor(session))
                .addInterceptor(log)
                .connectTimeout(20, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .writeTimeout(30, TimeUnit.SECONDS)
                .followRedirects(true)
                .build();
        moshi = new Moshi.Builder().build();
    }

    public static OkHttpClient http() {
        return client;
    }

    public static Moshi moshi() {
        return moshi;
    }

    public static <T> JsonAdapter<T> adapter(Class<T> cls) {
        return moshi.adapter(cls);
    }

    public static String extractCsrf(String html) {
        if (html == null || html.isEmpty()) return null;
        try {
            Document doc = Jsoup.parse(html);
            Element script = doc.selectFirst("script#page-data");
            if (script != null) {
                try {
                    JSONObject o = new JSONObject(script.data());
                    String csrf = o.optString("csrf", null);
                    if (csrf != null && !csrf.isEmpty()) return csrf;
                } catch (Exception ignored) {}
            }
            Element input = doc.selectFirst("input[name=_csrf]");
            if (input != null) return input.attr("value");
            Element meta = doc.selectFirst("meta[name=csrf-token]");
            if (meta != null) return meta.attr("content");
        } catch (Exception ignored) {}
        return null;
    }
}
