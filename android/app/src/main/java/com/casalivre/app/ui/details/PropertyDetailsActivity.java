package com.casalivre.app.ui.details;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import com.casalivre.app.R;
import com.casalivre.app.data.models.Property;
import com.casalivre.app.data.repo.CasaLivreRepository;
import com.casalivre.app.ui.booking.BookingActivity;
import java.util.Collections;

public class PropertyDetailsActivity extends AppCompatActivity {
    public static final String EXTRA_HASH = "hash";
    private CasaLivreRepository repo;
    private Property property;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_property_details);
        repo = new CasaLivreRepository(this);
        String hash = getIntent().getStringExtra(EXTRA_HASH);
        if (hash == null) { finish(); return; }

        repo.property(hash, new CasaLivreRepository.Cb<>() {
            @Override public void onSuccess(Property p) { property = p; bind(p); }
            @Override public void onError(Exception e) {
                Toast.makeText(PropertyDetailsActivity.this, "Erro: " + e.getMessage(), Toast.LENGTH_LONG).show();
                finish();
            }
        });
    }

    private void bind(Property p) {
        findViewById(R.id.progress).setVisibility(View.GONE);
        ((TextView) findViewById(R.id.title)).setText(
                p.displayTitle() != null ? p.displayTitle() : "Imóvel");
        ((TextView) findViewById(R.id.city)).setText(p.displayCity() != null ? p.displayCity() : "");
        ((TextView) findViewById(R.id.desc)).setText(
                p.description != null ? p.description : (p.descricao != null ? p.descricao : ""));
        Double price = p.price();
        ((TextView) findViewById(R.id.price)).setText(
                price != null ? String.format("R$ %.2f / noite", price) : "Consulte valor");
        StringBuilder am = new StringBuilder();
        if (p.amenityList() != null) {
            for (String a : p.amenityList()) {
                if (am.length() > 0) am.append(", ");
                am.append(a);
            }
        }
        ((TextView) findViewById(R.id.amenities)).setText(am.toString());

        RecyclerView rv = findViewById(R.id.photos);
        rv.setLayoutManager(new LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false));
        rv.setAdapter(new PhotoAdapter(p.photoList() != null ? p.photoList() : Collections.emptyList()));

        findViewById(R.id.btn_book).setOnClickListener(v -> {
            Intent i = new Intent(this, BookingActivity.class);
            i.putExtra(BookingActivity.EXTRA_HASH, p.hash != null ? p.hash : p.id);
            startActivity(i);
        });
    }
}
