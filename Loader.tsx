import App from "@hubleto/react-ui/core/App";
import TableExports from "./Components/TableExports";
import { FormExport } from "./Components/FormExport";

class Einvoice extends App {
  init() {
    super.init();

    globalThis.hubleto.registerReactComponent("TableExports", TableExports);
    globalThis.hubleto.registerReactComponent("FormExport", FormExport);
  }
}

globalThis.hubleto.registerApp(
  "Hubleto/App/Enterprise/Einvoice",
  new Einvoice(),
);
