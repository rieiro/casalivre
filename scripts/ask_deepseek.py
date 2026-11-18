#!/usr/bin/env python3
"""
CLI bridge to query DeepSeek models using the OpenAI-compatible SDK.
Defaults to the best available DeepSeek model (deepseek-reasoner).
"""
import argparse
import os
import sys
from pathlib import Path


def load_env_file(env_path: Path):
    """Load key-value pairs from an env file if present."""
    if not env_path.is_file():
        return
    with open(env_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip("'\"")
            if k not in os.environ:
                os.environ[k] = v


def main():
    repo_root = Path(__file__).resolve().parent.parent
    load_env_file(repo_root / ".env.agents")

    api_key = os.environ.get("DEEPSEEK_API_KEY")
    if not api_key:
        print("Error: DEEPSEEK_API_KEY not found in environment or .env.agents", file=sys.stderr)
        sys.exit(1)

    default_model = os.environ.get("DEEPSEEK_MODEL", "deepseek-reasoner")
    base_url = os.environ.get("DEEPSEEK_BASE_URL", "https://api.deepseek.com")

    parser = argparse.ArgumentParser(description="Query DeepSeek models via OpenAI SDK")
    parser.add_argument("spec_file", nargs="?", help="Path to specification/prompt markdown file (or read stdin)")
    parser.add_argument("--model", default=default_model, help=f"DeepSeek model to use (default: {default_model})")
    parser.add_argument("--system", default="You are an expert principal software architect and senior backend engineer. Produce clean, production-ready code with complete implementations.", help="System prompt")
    parser.add_argument("--no-stream", action="store_true", help="Disable response streaming")
    args = parser.parse_args()

    # Read user prompt
    if args.spec_file and args.spec_file != "-":
        spec_path = Path(args.spec_file)
        if not spec_path.is_file():
            print(f"Error: Spec file '{args.spec_file}' not found", file=sys.stderr)
            sys.exit(1)
        with open(spec_path, "r", encoding="utf-8") as f:
            user_prompt = f.read()
    else:
        if sys.stdin.isatty():
            print("Error: No input file provided and stdin is empty", file=sys.stderr)
            parser.print_help(sys.stderr)
            sys.exit(1)
        user_prompt = sys.stdin.read()

    try:
        from openai import OpenAI
    except ImportError:
        print("Error: 'openai' package is required. Run 'pip install openai'", file=sys.stderr)
        sys.exit(1)

    client = OpenAI(api_key=api_key, base_url=base_url)

    messages = [
        {"role": "system", "content": args.system},
        {"role": "user", "content": user_prompt},
    ]

    try:
        if args.no_stream:
            response = client.chat.completions.create(
                model=args.model,
                messages=messages,
            )
            print(response.choices[0].message.content or "")
        else:
            stream = client.chat.completions.create(
                model=args.model,
                messages=messages,
                stream=True,
            )
            for chunk in stream:
                delta = chunk.choices[0].delta
                if hasattr(delta, "content") and delta.content:
                    sys.stdout.write(delta.content)
                    sys.stdout.flush()
            sys.stdout.write("\n")
            sys.stdout.flush()
    except Exception as e:
        print(f"DeepSeek API error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
