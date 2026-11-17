package com.casalivre.app.ui.explore;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;
import com.bumptech.glide.Glide;
import com.casalivre.app.R;
import com.casalivre.app.data.models.Property;
import java.util.ArrayList;
import java.util.List;

public class PropertyAdapter extends RecyclerView.Adapter<PropertyAdapter.VH> {
    public interface OnClick { void onClick(Property p); }
    private final List<Property> data = new ArrayList<>();
    private final OnClick onClick;

    public PropertyAdapter(OnClick onClick) { this.onClick = onClick; }

    public void setData(List<Property> items) {
        data.clear();
        if (items != null) data.addAll(items);
        notifyDataSetChanged();
    }

    @NonNull
    @Override
    public VH onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View v = LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_property, parent, false);
        return new VH(v);
    }

    @Override
    public void onBindViewHolder(@NonNull VH h, int pos) {
        Property p = data.get(pos);
        h.title.setText(p.displayTitle() != null ? p.displayTitle() : "Imóvel");
        h.subtitle.setText(p.displayCity() != null ? p.displayCity() : "");
        Double price = p.price();
        h.price.setText(price != null
                ? String.format("R$ %.2f / noite", price)
                : "Consulte");
        String photo = null;
        if (p.photoList() != null && !p.photoList().isEmpty()) {
            photo = p.photoList().get(0);
        }
        if (photo != null && !photo.startsWith("http")) {
            photo = "http://192.168.0.115" + (photo.startsWith("/") ? "" : "/") + photo;
        }
        Glide.with(h.img).load(photo)
                .placeholder(R.color.surface_grid)
                .centerCrop()
                .into(h.img);
        h.itemView.setOnClickListener(v -> onClick.onClick(p));
    }

    @Override
    public int getItemCount() {
        return data.size();
    }

    static class VH extends RecyclerView.ViewHolder {
        ImageView img;
        TextView title, subtitle, price;
        VH(View v) {
            super(v);
            img = v.findViewById(R.id.img);
            title = v.findViewById(R.id.title);
            subtitle = v.findViewById(R.id.subtitle);
            price = v.findViewById(R.id.price);
        }
    }
}
