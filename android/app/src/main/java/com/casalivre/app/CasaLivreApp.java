package com.casalivre.app;

import android.app.Application;
import android.webkit.CookieManager;
import com.casalivre.app.data.api.ApiClient;

public class CasaLivreApp extends Application {
    @Override
    public void onCreate() {
        super.onCreate();
        CookieManager.getInstance().setAcceptCookie(true);
        ApiClient.init(this);
    }
}
