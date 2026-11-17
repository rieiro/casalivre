package com.casalivre.app.data.repo;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import com.casalivre.app.data.SessionManager;
import com.casalivre.app.data.api.ApiClient;
import com.casalivre.app.data.api.ApiException;
import com.casalivre.app.data.models.HostDashboard;
import com.casalivre.app.data.models.Property;
import com.casalivre.app.data.models.Quote;
import com.casalivre.app.data.models.SearchResponse;
import com.casalivre.app.data.models.User;
import java.io.IOException;
import java.util.HashMap;
import java.util.Map;
import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.HttpUrl;
import okhttp3.MediaType;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public class CasaLivreRepository {
    public interface Cb<T> { void onSuccess(T data); void onError(Exception e); }
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private final SessionManager session;
    private final Handler main = new Handler(Looper.getMainLooper());

    public CasaLivreRepository(Context ctx) {
        this.session = SessionManager.get(ctx);
    }

    public void fetchCsrf(Cb<String> cb) {
        Request req = new Request.Builder()
                .url(ApiClient.BASE_URL + "entrar")
                .header("Accept", "text/html")
                .build();
        ApiClient.http().newCall(req).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException e) { postErr(cb, e); }
            @Override public void onResponse(Call call, Response r) throws IOException {
                try {
                    if (!r.isSuccessful()) throw new ApiException(r.code(), "entrar");
                    String html = r.body() != null ? r.body().string() : "";
                    String csrf = ApiClient.extractCsrf(html);
                    if (csrf == null) throw new IOException("Could not parse CSRF");
                    session.setCsrf(csrf);
                    postOk(cb, csrf);
                } catch (Exception e) { postErr(cb, e); }
                finally { r.close(); }
            }
        });
    }

    public void login(String email, String password, Cb<User> cb) {
        fetchCsrf(new Cb<>() {
            @Override public void onSuccess(String csrf) {
                try {
                    Map<String,Object> body = new HashMap<>();
                    body.put("email", email);
                    body.put("password", password);
                    body.put("_csrf", csrf);
                    String json = ApiClient.moshi().adapter(Map.class).toJson(body);

                    Request req = new Request.Builder()
                            .url(ApiClient.BASE_URL + "api/sessao")
                            .post(RequestBody.create(json, JSON))
                            .header("Content-Type", "application/json")
                            .header("Accept", "application/json")
                            .build();
                    ApiClient.http().newCall(req).enqueue(new Callback() {
                        @Override public void onFailure(Call call, IOException e) { postErr(cb, e); }
                        @Override public void onResponse(Call call, Response r) {
                            try {
                                String payload = r.body() != null ? r.body().string() : "";
                                if (!r.isSuccessful()) throw new ApiException(r.code(), payload);
                                Map m = ApiClient.moshi().adapter(Map.class).fromJson(payload);
                                Map userMap = (Map) m.get("user");
                                User u = ApiClient.moshi().adapter(User.class).fromJsonValue(userMap);
                                session.setUserJson(ApiClient.moshi().adapter(User.class).toJson(u));
                                postOk(cb, u);
                            } catch (Exception e) { postErr(cb, e); }
                            finally { r.close(); }
                        }
                    });
                } catch (Exception e) { postErr(cb, e); }
            }
            @Override public void onError(Exception e) { postErr(cb, e); }
        });
    }

    public void logout(Runnable done) {
        Request req = new Request.Builder()
                .url(ApiClient.BASE_URL + "api/sessao")
                .delete().build();
        ApiClient.http().newCall(req).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException e) { main.post(done); }
            @Override public void onResponse(Call call, Response r) { r.close(); main.post(done); }
        });
    }

    public void search(String local, String checkin, String checkout, Integer guests, Cb<SearchResponse> cb) {
        HttpUrl.Builder b = HttpUrl.parse(ApiClient.BASE_URL + "api/busca").newBuilder();
        if (local != null && !local.isEmpty()) b.addQueryParameter("localidade", local);
        if (checkin != null && !checkin.isEmpty()) b.addQueryParameter("checkin", checkin);
        if (checkout != null && !checkout.isEmpty()) b.addQueryParameter("checkout", checkout);
        if (guests != null) b.addQueryParameter("hospedes", String.valueOf(guests));
        Request req = new Request.Builder().url(b.build()).header("Accept", "application/json").build();
        enqueue(req, SearchResponse.class, cb);
    }

    public void property(String hash, Cb<Property> cb) {
        get("api/imoveis/" + hash, Property.class, cb);
    }

    public void quote(String hash, String checkin, String checkout, Cb<Quote> cb) {
        try {
            Map<String,Object> body = new HashMap<>();
            body.put("checkin", checkin);
            body.put("checkout", checkout);
            String json = ApiClient.moshi().adapter(Map.class).toJson(body);
            Request req = new Request.Builder()
                    .url(ApiClient.BASE_URL + "api/imoveis/" + hash + "/reservar")
                    .post(RequestBody.create(json, JSON))
                    .header("Content-Type", "application/json").build();
            enqueue(req, Quote.class, cb);
        } catch (Exception e) { postErr(cb, e); }
    }

    public void hostDashboard(Cb<HostDashboard> cb) {
        get("api/painel", HostDashboard.class, cb);
    }

    private <T> void get(String path, Class<T> cls, Cb<T> cb) {
        Request req = new Request.Builder()
                .url(ApiClient.BASE_URL + path)
                .header("Accept", "application/json").build();
        enqueue(req, cls, cb);
    }

    private <T> void enqueue(Request req, Class<T> cls, Cb<T> cb) {
        ApiClient.http().newCall(req).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException e) { postErr(cb, e); }
            @Override public void onResponse(Call call, Response r) {
                try {
                    String body = r.body() != null ? r.body().string() : "";
                    if (!r.isSuccessful()) throw new ApiException(r.code(), body);
                    T data = ApiClient.moshi().adapter(cls).fromJson(body);
                    postOk(cb, data);
                } catch (Exception e) { postErr(cb, e); }
                finally { r.close(); }
            }
        });
    }

    private <T> void postOk(Cb<T> cb, T v) { main.post(() -> cb.onSuccess(v)); }
    private <T> void postErr(Cb<T> cb, Exception e) { main.post(() -> cb.onError(e)); }
}
