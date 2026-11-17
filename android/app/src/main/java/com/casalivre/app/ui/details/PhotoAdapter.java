package com.casalivre.app.ui.details;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;
import com.bumptech.glide.Glide;
import com.casalivre.app.R;
import java.util.List;

public class PhotoAdapter extends RecyclerView.Adapter<PhotoAdapter.VH> {
    private final List<String> photos;
    public PhotoAdapter(List<String> photos) { this.photos = photos; }

    @NonNull
    @Override
    public VH onCreateViewHolder(@NonNull ViewGroup p, int vt) {
        return new VH(LayoutInflater.from(p.getContext())
                .inflate(R.layout.item_photo, p, false));
    }

    @Override
    public void onBindViewHolder(@NonNull VH h, int pos) {
        String url = photos.get(pos);
        if (!url.startsWith("http")) {
            url = "http://192.168.0.115" + (url.startsWith("/") ? "" : "/") + url;
        }
        Glide.with(h.iv).load(url).centerCrop().into(h.iv);
    }

    @Override
    public int getItemCount() {
        return photos != null ? photos.size() : 0;
    }

    static class VH extends RecyclerView.ViewHolder {
        ImageView iv;
        VH(View v) { super(v); iv = (ImageView) v; }
    }
}
