package com.casalivre.app.data.models;

import java.util.List;

public class Property {
    public String hash;
    public String id;
    public String titulo;
    public String title;
    public String descricao;
    public String description;
    public String localidade;
    public String city;
    public List<String> fotos;
    public List<String> photos;
    public List<String> comodidades;
    public List<String> amenities;
    public Double preco_noite;
    public Double nightly_price;
    public Double price;
    public Double score;
    public Integer capacidade;
    public Integer guests;
    public Integer quartos;
    public Integer bedrooms;
    public List<String> dias_bloqueados;
    public List<String> blocked_days;

    public String displayTitle() {
        return titulo != null ? titulo : title;
    }
    public String displayCity() {
        return localidade != null ? localidade : city;
    }
    public List<String> photoList() {
        return photos != null ? photos : fotos;
    }
    public List<String> amenityList() {
        return amenities != null ? amenities : comodidades;
    }
    public Double price() {
        return price != null ? price : (preco_noite != null ? preco_noite : nightly_price);
    }
}
