package com.casalivre.app.ui.web;

import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import com.casalivre.app.R;

public class WebViewLauncher extends AppCompatActivity {
    public static void open(Context ctx, String title, String path) {
        Intent i = new Intent(ctx, WebViewLauncher.class);
        i.putExtra("title", title);
        i.putExtra("path", path);
        ctx.startActivity(i);
    }

    @Override
    protected void onCreate(@Nullable Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_main);
        findViewById(R.id.bottom_nav).setVisibility(android.view.View.GONE);
        WebShellFragment f = WebShellFragment.newInstance(
                getIntent().getStringExtra("path"), getIntent().getStringExtra("title"));
        getSupportFragmentManager().beginTransaction()
                .replace(R.id.fragment_container, f).commit();
    }
}
