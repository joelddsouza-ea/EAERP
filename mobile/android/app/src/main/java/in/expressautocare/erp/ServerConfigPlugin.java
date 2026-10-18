package in.expressautocare.erp;

import android.content.Context;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * The ERP address saved on the phone: the setup screen sets it, Settings › "Change server
 * address" clears it. Either way the app restarts on the new address.
 */
@CapacitorPlugin(name = "ServerConfig")
public class ServerConfigPlugin extends Plugin {

    private static final String PREFS = "eaerp";
    private static final String KEY = "server_url";
    static String suggested = "";

    static String savedUrl(Context context) {
        String url = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null);
        return url == null || url.isEmpty() ? null : url;
    }

    @PluginMethod
    public void get(PluginCall call) {
        JSObject ret = new JSObject();
        ret.put("url", savedUrl(getContext()));
        ret.put("suggested", suggested);
        call.resolve(ret);
    }

    @PluginMethod
    public void set(PluginCall call) {
        String url = call.getString("url", "").trim();
        if (!url.matches("^https?://[^\\s/]+(:\\d+)?(/\\S*)?$")) {
            call.reject("Enter the full address, e.g. https://erp.example.in or http://192.168.1.20:8000");
            return;
        }
        while (url.endsWith("/")) {
            url = url.substring(0, url.length() - 1);
        }
        getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, url).apply();
        call.resolve();
        restart();
    }

    @PluginMethod
    public void reset(PluginCall call) {
        getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().remove(KEY).apply();
        call.resolve();
        restart();
    }

    private void restart() {
        getActivity().runOnUiThread(() -> getActivity().recreate());
    }
}
