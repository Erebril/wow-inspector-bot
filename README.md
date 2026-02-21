# wow-inspector-bot
A Discord bot for World of Warcraft Classic Anniversary that retrieves character armory data using the Blizzard API.

## Features

- **Character Equipment**: Displays equipped items with quality indicators and item level requirements
- **Character Statistics**: Shows detailed stats (HP, Power, Attributes, Critical Strike %)
- **Faction Detection**: Color-coded embeds (Red for Horde, Blue for Alliance)
- **Character Media**: Displays character thumbnail from Blizzard's render service
- **WarcraftLogs Integration**: Direct link to character logs

## Setup

### Requirements
- PHP 8.0+
- Composer
- Discord Bot Token
- Blizzard API Credentials

### Installation

1. Clone the repository
2. Install dependencies:
    ```bash
    composer install
    ```

3. Create a `.env` file:
    ```
    DISCORD_TOKEN=your_discord_token
    BLIZZARD_CLIENT_ID=your_client_id
    BLIZZARD_SECRET=your_client_secret
    ```

4. Run the bot:
    ```bash
    php bot.php
    ```

## Usage

Use the `/gs` command in Discord:
```
/gs nombre:<character_name> reino:<realm_name>
```

The bot will return:
- Character header with level, race, class, and guild
- Equipment list with quality and level indicators
- Detailed character statistics
- Armory link via WarcraftLogs

## API

Uses Blizzard's World of Warcraft Profile API with `profile-classicann-us` namespace for Classic Anniversary data.

## Author

Erebril