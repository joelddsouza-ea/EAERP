package in.expressautocare.erp;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;
import com.getcapacitor.Logger;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import org.json.JSONObject;

/**
 * Opens the ERP at the address saved on this phone (first run: the setup screen in www/
 * asks for it). The address can't be fixed in the build: during testing it is a PC on the
 * workshop Wi-Fi, later the public site.
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(ServerConfigPlugin.class);

        try {
            JSONObject json = new JSONObject(readAsset("capacitor.config.json"));
            JSONObject server = json.optJSONObject("server");
            if (server == null) {
                server = new JSONObject();
                json.put("server", server);
            }
            // A build-time address is only offered on the setup screen
            ServerConfigPlugin.suggested = server.optString("url", "");

            String url = ServerConfigPlugin.savedUrl(this);
            if (url != null) {
                server.put("url", url);
                server.put("cleartext", url.startsWith("http://"));
                // Server down / offline: a local page with "Try again" and "Change server address"
                server.put("errorPath", "error.html");
            } else {
                server.remove("url");
            }
            this.config = new CapConfig(getAssets(), json);
        } catch (Exception e) {
            Logger.error("EA Workshop: could not apply the server address", e);
        }

        super.onCreate(savedInstanceState);
    }

    private String readAsset(String name) throws Exception {
        try (InputStream in = getAssets().open(name); ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            byte[] buf = new byte[4096];
            int n;
            while ((n = in.read(buf)) > 0) {
                out.write(buf, 0, n);
            }
            return out.toString("UTF-8");
        }
    }
}
