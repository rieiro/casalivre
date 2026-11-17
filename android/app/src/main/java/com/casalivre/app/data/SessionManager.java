package com.casalivre.app.data;

import android.content.Context;
import android.content.SharedPreferences;

public class SessionManager {
    private static final String PREF = "casalivre_session";
    private static final String K_CSRF = "csrf";
    private static final String K_USER = "user_json";

    private final SharedPreferences prefs;
    private static SessionManager instance;

    public static synchronized SessionManager get(Context ctx) {
        if (instance == null) {
            instance = new SessionManager(ctx.getApplicationContext());
        }
        return instance;
    }

    private SessionManager(Context ctx) {
        prefs = ctx.getSharedPreferences(PREF, Context.MODE_PRIVATE);
    }

    public String getCsrf() {
        return prefs.getString(K_CSRF, null);
    }

    public void setCsrf(String csrf) {
        prefs.edit().putString(K_CSRF, csrf).apply();
    }

    public String getUserJson() {
        return prefs.getString(K_USER, null);
    }

    public void setUserJson(String json) {
        prefs.edit().putString(K_USER, json).apply();
    }

    public boolean isLoggedIn() {
        return getUserJson() != null;
    }

    public void clear() {
        prefs.edit().clear().apply();
    }
}
