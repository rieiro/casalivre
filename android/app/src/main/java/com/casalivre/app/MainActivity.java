package com.casalivre.app;

import android.os.Bundle;
import androidx.appcompat.app.AppCompatActivity;
import androidx.fragment.app.Fragment;
import com.casalivre.app.ui.account.AccountFragment;
import com.casalivre.app.ui.bookings.BookingsFragment;
import com.casalivre.app.ui.explore.ExploreFragment;
import com.casalivre.app.ui.host.HostFragment;
import com.google.android.material.bottomnavigation.BottomNavigationView;

public class MainActivity extends AppCompatActivity {
    private BottomNavigationView nav;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_main);
        nav = findViewById(R.id.bottom_nav);
        nav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_explore) {
                load(new ExploreFragment());
                return true;
            } else if (id == R.id.nav_bookings) {
                load(new BookingsFragment());
                return true;
            } else if (id == R.id.nav_host) {
                load(new HostFragment());
                return true;
            } else if (id == R.id.nav_account) {
                load(new AccountFragment());
                return true;
            }
            return false;
        });
        if (b == null) {
            nav.setSelectedItemId(R.id.nav_explore);
        }
    }

    private void load(Fragment f) {
        getSupportFragmentManager()
                .beginTransaction()
                .replace(R.id.fragment_container, f)
                .commitAllowingStateLoss();
    }

    @Override
    public void onBackPressed() {
        Fragment f = getSupportFragmentManager().findFragmentById(R.id.fragment_container);
        if (f instanceof BackHandler && ((BackHandler) f).onBackPressed()) {
            return;
        }
        super.onBackPressed();
    }

    public interface BackHandler {
        boolean onBackPressed();
    }
}
