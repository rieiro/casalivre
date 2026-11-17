package com.casalivre.app.ui.account;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import com.casalivre.app.R;
import com.casalivre.app.data.SessionManager;
import com.casalivre.app.data.api.ApiClient;
import com.casalivre.app.data.models.User;
import com.casalivre.app.data.repo.CasaLivreRepository;
import com.casalivre.app.ui.auth.LoginActivity;
import com.casalivre.app.ui.web.WebViewLauncher;

public class AccountFragment extends Fragment {
    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup parent, @Nullable Bundle state) {
        View v = inf.inflate(R.layout.fragment_account, parent, false);
        TextView name = v.findViewById(R.id.name);
        TextView email = v.findViewById(R.id.email);

        User u = null;
        try {
            String json = SessionManager.get(requireContext()).getUserJson();
            u = ApiClient.moshi().adapter(User.class).fromJson(json);
        } catch (Exception ignored) {}

        if (u != null) {
            name.setText(u.displayName());
            email.setText(u.email);
        }

        v.findViewById(R.id.btn_edit).setOnClickListener(x ->
                WebViewLauncher.open(requireContext(), "Conta", "conta"));

        v.findViewById(R.id.btn_logout).setOnClickListener(x -> {
            new CasaLivreRepository(requireContext()).logout(() -> {
                SessionManager.get(requireContext()).clear();
                CookieManager.getInstance().removeAllCookies(null);
                CookieManager.getInstance().flush();
                Intent i = new Intent(requireContext(), LoginActivity.class);
                i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
                startActivity(i);
                requireActivity().finish();
            });
        });

        return v;
    }
}
