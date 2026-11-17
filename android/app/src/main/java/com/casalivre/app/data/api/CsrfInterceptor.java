package com.casalivre.app.data.api;

import androidx.annotation.NonNull;
import com.casalivre.app.data.SessionManager;
import java.io.IOException;
import okhttp3.Interceptor;
import okhttp3.Request;
import okhttp3.Response;

public class CsrfInterceptor implements Interceptor {
    private final SessionManager session;

    public CsrfInterceptor(SessionManager session) {
        this.session = session;
    }

    @NonNull
    @Override
    public Response intercept(@NonNull Chain chain) throws IOException {
        Request req = chain.request();
        String method = req.method();
        boolean mutating = method.equals("POST") || method.equals("PUT")
                || method.equals("DELETE") || method.equals("PATCH");

        Request.Builder rb = req.newBuilder();
        if (mutating) {
            String csrf = session.getCsrf();
            if (csrf != null) {
                rb.header("X-CSRF-Token", csrf);
            }
            rb.header("Accept", "application/json");
        }
        Response resp = chain.proceed(rb.build());

        if (mutating && (resp.code() == 419 || resp.code() == 403)) {
            resp.close();
            if (refreshCsrf(chain)) {
                Request retry = rb.header("X-CSRF-Token", session.getCsrf()).build();
                return chain.proceed(retry);
            }
        }
        return resp;
    }

    private boolean refreshCsrf(Chain chain) {
        try {
            Request entrar = new Request.Builder()
                    .url(ApiClient.BASE_URL + "entrar")
                    .header("Accept", "text/html")
                    .build();
            Response r = chain.proceed(entrar);
            if (!r.isSuccessful()) {
                r.close();
                return false;
            }
            String html = r.body() != null ? r.body().string() : "";
            r.close();
            String csrf = ApiClient.extractCsrf(html);
            if (csrf != null) {
                session.setCsrf(csrf);
                return true;
            }
        } catch (IOException ignored) {}
        return false;
    }
}
