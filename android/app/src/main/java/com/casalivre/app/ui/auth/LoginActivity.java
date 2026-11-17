package com.casalivre.app.ui.auth;

import android.content.Intent;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import com.casalivre.app.MainActivity;
import com.casalivre.app.R;
import com.casalivre.app.data.SessionManager;
import com.casalivre.app.data.models.User;
import com.casalivre.app.data.repo.CasaLivreRepository;

public class LoginActivity extends AppCompatActivity {
    private TextView emailEt, passEt;
    private Button loginBtn;
    private ProgressBar progress;
    private CasaLivreRepository repo;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        if (SessionManager.get(this).isLoggedIn()) {
            startActivity(new Intent(this, MainActivity.class));
            finish();
            return;
        }
        setContentView(R.layout.activity_login);
        emailEt = findViewById(R.id.email);
        passEt  = findViewById(R.id.password);
        loginBtn = findViewById(R.id.login_button);
        progress = findViewById(R.id.login_progress);
        repo = new CasaLivreRepository(this);
        loginBtn.setOnClickListener(v -> attemptLogin());
    }

    private void attemptLogin() {
        String email = emailEt.getText().toString().trim();
        String pass  = passEt.getText().toString();
        if (TextUtils.isEmpty(email) || TextUtils.isEmpty(pass)) {
            Toast.makeText(this, "Informe e-mail e senha.", Toast.LENGTH_SHORT).show();
            return;
        }
        setBusy(true);
        repo.login(email, pass, new CasaLivreRepository.Cb<>() {
            @Override public void onSuccess(User user) {
                setBusy(false);
                startActivity(new Intent(LoginActivity.this, MainActivity.class));
                finish();
            }
            @Override public void onError(Exception e) {
                setBusy(false);
                String msg = e.getMessage();
                if (msg != null && (msg.contains("401") || msg.contains("422") || msg.contains("400"))) {
                    msg = "Credenciais inválidas.";
                }
                Toast.makeText(LoginActivity.this, msg, Toast.LENGTH_LONG).show();
            }
        });
    }

    private void setBusy(boolean busy) {
        progress.setVisibility(busy ? View.VISIBLE : View.GONE);
        loginBtn.setEnabled(!busy);
    }
}
