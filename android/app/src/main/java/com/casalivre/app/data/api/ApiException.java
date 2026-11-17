package com.casalivre.app.data.api;

public class ApiException extends Exception {
    public final int code;
    public final String body;
    public ApiException(int code, String body) {
        super("HTTP " + code + ": " + body);
        this.code = code;
        this.body = body;
    }
}
