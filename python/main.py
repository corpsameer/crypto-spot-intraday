from cryptospot.logger import get_logger


def main():
    print("Crypto Spot Intraday Python Tools")
    logger = get_logger(__name__)
    logger.info("Python environment loaded.")


if __name__ == "__main__":
    main()
