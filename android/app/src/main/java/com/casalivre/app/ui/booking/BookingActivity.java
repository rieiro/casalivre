package com.casalivre.app.ui.booking;

import android.app.DatePickerDialog;
import android.os.Bundle;
import android.view.View;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import com.casalivre.app.R;
import com.casalivre.app.data.models.Quote;
import com.casalivre.app.data.repo.CasaLivreRepository;
import com.casalivre.app.ui.web.WebViewLauncher;
import java.util.Calendar;

public class BookingActivity extends AppCompatActivity {
    public static final String EXTRA_HASH = "hash";
    private TextView etIn, etOut, quoteTotal;
    private ProgressBar progress;
    private com.google.android.material.button.MaterialButton payBtn;
    private String hash;
    private Quote quote;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_booking);
        hash = getIntent().getStringExtra(EXTRA_HASH);
        etIn = findViewById(R.id.et_in);
        etOut = findViewById(R.id.et_out);
        quoteTotal = findViewById(R.id.quote_total);
        progress = findViewById(R.id.progress);
        payBtn = findViewById(R.id.btn_pay);

        etIn.setOnClickListener(v -> pick(etIn));
        etOut.setOnClickListener(v -> pick(etOut));
        findViewById(R.id.btn_quote).setOnClickListener(v -> fetchQuote());

        payBtn.setOnClickListener(v -> {
            if (quote != null && quote.redirect != null) {
                String path = quote.redirect.startsWith("/") ? quote.redirect.substring(1) : quote.redirect;
                WebViewLauncher.open(this, "Pagamento Pix", path);
            } else {
                String path = "pagamento/" + (quote != null && quote.quote_hash != null ? quote.quote_hash : hash);
                WebViewLauncher.open(this, "Pagamento Pix", path);
            }
        });
    }

    private void pick(TextView target) {
        Calendar c = Calendar.getInstance();
        new DatePickerDialog(this, (v, y, m, d) ->
                target.setText(String.format("%04d-%02d-%02d", y, m+1, d)),
                c.get(Calendar.YEAR), c.get(Calendar.MONTH),
                c.get(Calendar.DAY_OF_MONTH)).show();
    }

    private void fetchQuote() {
        String in = etIn.getText().toString();
        String out = etOut.getText().toString();
        if (in.isEmpty() || out.isEmpty()) {
            Toast.makeText(this, "Escolha check-in e check-out.", Toast.LENGTH_SHORT).show();
            return;
        }
        progress.setVisibility(View.VISIBLE);
        new CasaLivreRepository(this).quote(hash, in, out, new CasaLivreRepository.Cb<>() {
            @Override public void onSuccess(Quote q) {
                progress.setVisibility(View.GONE);
                quote = q;
                Double total = q.total != null ? q.total : q.subtotal;
                quoteTotal.setText(total != null
                        ? String.format("Total: R$ %.2f", total)
                        : "Cotação calculada.");
                payBtn.setEnabled(true);
            }
            @Override public void onError(Exception e) {
                progress.setVisibility(View.GONE);
                Toast.makeText(BookingActivity.this, "Erro: " + e.getMessage(), Toast.LENGTH_LONG).show();
            }
        });
    }
}
