import App from "@hubleto/react-ui/core/App";
import TableEinvoices from "./Components/FC/TableEinvoices";
import FormEinvoice from "./Components/FC/FormEinvoice";

class Einvoice extends App {
  init() {
    super.init();

    globalThis.hubleto.registerReactComponent("TableEinvoices", TableEinvoices);
    globalThis.hubleto.registerReactComponent("FormEinvoice", FormEinvoice);
  }
}

globalThis.hubleto.registerApp(
  "Hubleto/App/External/WaiBlue/Einvoice",
  new Einvoice(),
);
