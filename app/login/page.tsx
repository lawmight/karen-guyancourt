import { loginWithKey } from "./actions";

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const params = await searchParams;
  const showError = params.error === "1";

  return (
    <div className="root">
      <h1>Acces prive</h1>
      <form action={loginWithKey} className="form">
        <label htmlFor="key">Mot de passe d'acces</label>
        <input
          id="key"
          name="key"
          type="password"
          required
          autoComplete="current-password"
          className="input"
          placeholder="Entrez la cle d'acces"
        />
        {showError ? <p className="form-error">Cle invalide. Veuillez reessayer.</p> : null}
        <button type="submit" className="btn btn-primary">
          Se connecter
        </button>
      </form>
    </div>
  );
}
