package com.casalivre.app.ui.bookings;

import android.os.Bundle;
import androidx.annotation.Nullable;
import com.casalivre.app.ui.web.WebShellFragment;

public class BookingsFragment extends WebShellFragment {
    @Override
    public void onCreate(@Nullable Bundle s) {
        Bundle b = new Bundle();
        b.putString(ARG_PATH, "reservas");
        b.putString(ARG_TITLE, "Reservas");
        setArguments(b);
        super.onCreate(s);
    }
}
