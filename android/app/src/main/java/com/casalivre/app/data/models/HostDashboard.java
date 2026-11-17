package com.casalivre.app.data.models;

import java.util.List;

public class HostDashboard {
    public List<Property> items;
    public List<Property> imoveis;
    public List<Property> properties;

    public List<Property> list() {
        if (items != null) return items;
        if (imoveis != null) return imoveis;
        return properties;
    }
}
