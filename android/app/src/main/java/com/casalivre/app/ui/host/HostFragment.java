package com.casalivre.app.ui.host;

import android.os.Bundle;
import androidx.annotation.Nullable;
import com.casalivre.app.ui.web.WebShellFragment;

public class HostFragment extends WebShellFragment {
    @Override
    public void onCreate(@Nullable Bundle s) {
        Bundle b = new Bundle();
        b.putString(ARG_PATH, "painel");
        b.putString(ARG_TITLE, "Painel do Anfitrião");
        setArguments(b);
        super.onCreate(s);
    }
}
