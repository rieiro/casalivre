package com.casalivre.app.ui.explore;

import android.app.DatePickerDialog;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;
import com.casalivre.app.R;
import com.casalivre.app.data.models.Property;
import com.casalivre.app.data.models.SearchResponse;
import com.casalivre.app.data.repo.CasaLivreRepository;
import com.casalivre.app.ui.details.PropertyDetailsActivity;
import java.util.Calendar;
import java.util.List;

public class ExploreFragment extends Fragment {
    private TextView etLocal, etCheckin, etCheckout, etGuests;
    private ProgressBar progress;
    private View empty;
    private SwipeRefreshLayout swipe;
    private PropertyAdapter adapter;
    private CasaLivreRepository repo;

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inf, @Nullable ViewGroup parent, @Nullable Bundle state) {
        View v = inf.inflate(R.layout.fragment_explore, parent, false);
        repo = new CasaLivreRepository(requireContext());

        etLocal = v.findViewById(R.id.et_local);
        etCheckin = v.findViewById(R.id.et_checkin);
        etCheckout = v.findViewById(R.id.et_checkout);
        etGuests = v.findViewById(R.id.et_guests);
        progress = v.findViewById(R.id.search_progress);
        empty = v.findViewById(R.id.et_empty);
        swipe = v.findViewById(R.id.swipe);

        RecyclerView rv = v.findViewById(R.id.rv);
        rv.setLayoutManager(new LinearLayoutManager(getContext()));
        adapter = new PropertyAdapter(this::openDetails);
        rv.setAdapter(adapter);

        etCheckin.setOnClickListener(x -> pickDate(etCheckin));
        etCheckout.setOnClickListener(x -> pickDate(etCheckout));
        v.findViewById(R.id.btn_search).setOnClickListener(x -> doSearch());
        swipe.setOnRefreshListener(this::doSearch);
        doSearch();
        return v;
    }

    private void pickDate(TextView target) {
        Calendar c = Calendar.getInstance();
        new DatePickerDialog(requireContext(), (view, y, m, d) -> {
            String iso = String.format("%04d-%02d-%02d", y, m+1, d);
            target.setText(iso);
        }, c.get(Calendar.YEAR), c.get(Calendar.MONTH), c.get(Calendar.DAY_OF_MONTH)).show();
    }

    private void openDetails(Property p) {
        Intent i = new Intent(requireContext(), PropertyDetailsActivity.class);
        i.putExtra(PropertyDetailsActivity.EXTRA_HASH, p.hash != null ? p.hash : p.id);
        startActivity(i);
    }

    private void doSearch() {
        progress.setVisibility(View.VISIBLE);
        empty.setVisibility(View.GONE);
        Integer guests = null;
        try { guests = Integer.parseInt(etGuests.getText().toString()); } catch (Exception ignored){}
        String in = etCheckin.getText().toString();
        String out = etCheckout.getText().toString();
        repo.search(etLocal.getText().toString(),
                in.isEmpty() ? null : in,
                out.isEmpty() ? null : out,
                guests,
                new CasaLivreRepository.Cb<>() {
                    @Override public void onSuccess(SearchResponse s) {
                        progress.setVisibility(View.GONE);
                        swipe.setRefreshing(false);
                        List<Property> list = s != null ? s.list() : null;
                        adapter.setData(list);
                        empty.setVisibility(list == null || list.isEmpty() ? View.VISIBLE : View.GONE);
                    }
                    @Override public void onError(Exception e) {
                        progress.setVisibility(View.GONE);
                        swipe.setRefreshing(false);
                        if (isAdded()) {
                            Toast.makeText(getContext(), "Erro de busca: " + e.getMessage(), Toast.LENGTH_LONG).show();
                        }
                    }
                });
    }
}
