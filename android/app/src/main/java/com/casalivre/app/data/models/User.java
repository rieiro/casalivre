package com.casalivre.app.data.models;

public class User {
    public Long id;
    public String nome;
    public String name;
    public String email;
    public String telefone;
    public String phone;
    public String avatar;
    public String avatar_url;
    public String role;

    public String displayName() {
        return nome != null ? nome : (name != null ? name : email);
    }
    public String avatarUrl() {
        return avatar != null ? avatar : avatar_url;
    }
}
