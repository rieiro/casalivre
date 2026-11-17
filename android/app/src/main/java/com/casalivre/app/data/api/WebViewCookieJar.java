package com.casalivre.app.data.api;

import android.webkit.CookieManager;
import java.util.ArrayList;
import java.util.List;
import okhttp3.Cookie;
import okhttp3.CookieJar;
import okhttp3.HttpUrl;

public class WebViewCookieJar implements CookieJar {
    private final CookieManager cm = CookieManager.getInstance();

    public WebViewCookieJar() {
        cm.setAcceptCookie(true);
    }

    @Override
    public void saveFromResponse(HttpUrl url, List<Cookie> cookies) {
        for (Cookie c : cookies) {
            cm.setCookie(url.toString(), c.toString());
        }
        cm.flush();
    }

    @Override
    public List<Cookie> loadForRequest(HttpUrl url) {
        String header = cm.getCookie(url.toString());
        if (header == null || header.isEmpty()) {
            return new ArrayList<>();
        }
        List<Cookie> out = new ArrayList<>();
        for (String part : header.split(";")) {
            Cookie c = Cookie.parse(url, part.trim());
            if (c != null) {
                out.add(c);
            }
        }
        return out;
    }
}
