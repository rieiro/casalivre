package com.casalivre.app.ui.web;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import com.casalivre.app.MainActivity;
import com.casalivre.app.R;
import com.casalivre.app.data.api.ApiClient;

public class WebShellFragment extends Fragment implements MainActivity.BackHandler {
    protected static final String ARG_PATH = "path";
    protected static final String ARG_TITLE = "title";

    protected WebView webView;
    protected ProgressBar progress;
    protected View errorView;
    protected String startPath;

    private ValueCallback<Uri[]> fileCallback;
    private ActivityResultLauncher<Intent> fileChooserLauncher;

    public static WebShellFragment newInstance(String path, String title) {
        WebShellFragment f = new WebShellFragment();
        Bundle b = new Bundle();
        b.putString(ARG_PATH, path);
        b.putString(ARG_TITLE, title);
        f.setArguments(b);
        return f;
    }

    @Override
    public void onCreate(@Nullable Bundle s) {
        super.onCreate(s);
        fileChooserLauncher = registerForActivityResult(
                new ActivityResultContracts.StartActivityForResult(), result -> {
                    if (fileCallback == null) return;
                    Uri[] results = null;
                    if (result.getResultCode() == Activity.RESULT_OK && result.getData() != null
                            && result.getData().getData() != null) {
                        results = new Uri[]{ result.getData().getData() };
                    }
                    fileCallback.onReceiveValue(results);
                    fileCallback = null;
                });
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup parent, @Nullable Bundle state) {
        View v = inf.inflate(R.layout.fragment_web_shell, parent, false);
        webView = v.findViewById(R.id.web_view);
        progress = v.findViewById(R.id.web_progress);
        errorView = v.findViewById(R.id.web_error);
        String path = getArguments() != null ? getArguments().getString(ARG_PATH, "") : "";
        startPath = ApiClient.BASE_URL + path;
        v.findViewById(R.id.web_retry).setOnClickListener(x -> {
            errorView.setVisibility(View.GONE);
            webView.reload();
        });

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        s.setUserAgentString(s.getUserAgentString() + " CasaLivreApp/1.0");

        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                Uri u = req.getUrl();
                String h = u.getHost();
                if (h != null && (h.equals("192.168.0.115") || h.equals("10.64.215.59") || h.equals("localhost"))) return false;
                try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) {}
                return true;
            }
            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                progress.setVisibility(View.GONE);
                injectMobileCss(view);
            }
            @Override
            public void onReceivedError(WebView view, WebResourceRequest r, WebResourceError e) {
                if (r.isForMainFrame()) {
                    errorView.setVisibility(View.VISIBLE);
                    progress.setVisibility(View.GONE);
                }
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int p) {
                progress.setVisibility(p < 100 ? View.VISIBLE : View.GONE);
                progress.setProgress(p);
            }
            @Override
            public boolean onShowFileChooser(WebView wv, ValueCallback<Uri[]> cb, FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = cb;
                Intent intent = params.createIntent();
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                try { fileChooserLauncher.launch(intent); }
                catch (Exception e) { fileCallback = null; return false; }
                return true;
            }
        });

        webView.loadUrl(startPath);
        return v;
    }

    private void injectMobileCss(WebView v) {
        String js = "(function(){"
                + "if(document.getElementById('__cl_mobile'))return;"
                + "var s=document.createElement('style');s.id='__cl_mobile';"
                + "s.textContent='body{padding-bottom:72px!important;} *{-webkit-tap-highlight-color:transparent;}';"
                + "document.head.appendChild(s);"
                + "var m=document.querySelector('meta[name=viewport]');"
                + "if(!m){m=document.createElement('meta');m.name='viewport';document.head.appendChild(m);}"
                + "m.content='width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no';"
                + "})();";
        v.evaluateJavascript(js, null);
    }

    @Override
    public boolean onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return true;
        }
        return false;
    }

    @Override
    public void onResume() {
        super.onResume();
        if (webView != null) CookieManager.getInstance().flush();
    }
}
